<?php

namespace Amp\Websocket;

use Amp\Deferred;
use Amp\Failure;
use Amp\Websocket;

class Rfc6455Endpoint implements Endpoint {
    private $application;
    private $proxy;
    private $closeTimeout;
    private $timeoutWatcher;
    private $now;

    private $autoFrameSize = 32768;
    private $maxFrameSize = 2097152;
    private $maxMsgSize = 10485760;
    private $heartbeatPeriod = 10;
    private $closePeriod = 3;
    private $validateUtf8 = false;
    private $textOnly = false;
    private $queuedPingLimit = 3;
    // @TODO add minimum average frame size rate threshold to prevent tiny-frame DoS

    private $socket;
    private $parser;
    private $builder = [];
    private $readWatcher;
    private $writeWatcher;
    private $msgPromisor;

    private $pingCount = 0;
    private $pongCount = 0;

    private $writeBuffer = '';
    private $writeDeferred;
    private $writeDataQueue = [];
    private $writeDeferredDataQueue = [];
    private $writeControlQueue = [];
    private $writeDeferredControlQueue = [];

    // getInfo() properties
    private $connectedAt;
    private $closedAt = 0;
    private $lastReadAt = 0;
    private $lastSentAt = 0;
    private $lastDataReadAt = 0;
    private $lastDataSentAt = 0;
    private $bytesRead = 0;
    private $bytesSent = 0;
    private $framesRead = 0;
    private $framesSent = 0;
    private $messagesRead = 0;
    private $messagesSent = 0;


    /* Frame control bits */
    const FIN      = 0b1;
    const RSV_NONE = 0b000;
    const OP_CONT  = 0x00;
    const OP_TEXT  = 0x01;
    const OP_BIN   = 0x02;
    const OP_CLOSE = 0x08;
    const OP_PING  = 0x09;
    const OP_PONG  = 0x0A;

    const CONTROL = 1;
    const DATA = 2;
    const ERROR = 3;

    public function __construct($socket, Websocket $application, array $headers = []) {
        if (!$headers) {
            throw new ClientException;
        }
        $this->application = $application;
        $this->now = time();
        $this->proxy = new Rfc6455EndpointProxy($this);
        $f = (new \ReflectionClass($this))->getMethod("timeout")->getClosure($this);
        $this->timeoutWatcher = \Amp\repeat($f, 1000);
        $this->connectedAt = $this->now;
        $this->socket = $socket;
        $this->parser = $this->parser([$this, "onParse"]);
        $this->writeWatcher = \Amp\onWritable($socket, [$this, "onWritable"], $options = ["enable" => false]);
        \Amp\resolve($this->tryAppOnOpen($headers));
    }

    private function tryAppOnOpen($headers) {
        $gen = $this->application->onOpen($this->proxy, $headers);
        if ($gen instanceof \Generator) {
            yield \Amp\resolve($gen);
        }
        $this->readWatcher = \Amp\onReadable($this->socket, [$this, "onReadable"]);
    }

    private function doClose($code, $reason) {
        // Only proceed if we haven't already begun the close handshake elsewhere
        if ($this->closedAt) {
            return;
        }

        $this->closeTimeout = $this->now + $this->closePeriod;
        $promise = $this->sendCloseFrame($code, $reason);
        return \Amp\pipe(\Amp\resolve($this->tryAppOnClose($code, $reason)), function() use ($promise) {
            return $promise;
        });
        // Don't unload the client here, it will be unloaded upon timeout
    }

    private function sendCloseFrame($code, $msg) {
        $promise = $this->compile(pack('n', $code) . $msg, self::OP_CLOSE);
        $this->closedAt = $this->now;
        return $promise;
    }

    private function tryAppOnClose($code, $reason) {
        $onCloseResult = $this->application->onClose($code, $reason);
        if ($onCloseResult instanceof \Generator) {
            yield \Amp\resolve($onCloseResult);
        }
    }

    private function unloadClient() {
        $this->parser = null;
        if ($this->readWatcher) {
            \Amp\cancel($this->readWatcher);
        }
        if ($this->writeWatcher) {
            \Amp\cancel($this->writeWatcher);
        }

        // fail not yet terminated message streams; they *must not* be failed before client is removed
        if ($this->msgPromisor) {
            $this->msgPromisor->fail(new ClientException);
        }

        if ($this->writeBuffer != "") {
            $this->writeDeferred->fail(new ClientException);
        }
        foreach ([$this->writeDeferredDataQueue, $this->writeDeferredControlQueue] as $deferreds) {
            foreach ($deferreds as $deferred) {
                $deferred->fail(new ClientException);
            }
        }
    }

    public function onParse(array $parseResult) {
        switch (array_shift($parseResult)) {
            case self::CONTROL:
                $this->onParsedControlFrame($parseResult);
                break;
            case self::DATA:
                $this->onParsedData($parseResult);
                break;
            case self::ERROR:
                $this->onParsedError($parseResult);
                break;
            default:
                assert(false, "Unknown Rfc6455Parser result code");
        }
    }

    private function onParsedControlFrame(array $parseResult) {
        // something went that wrong that we had to shutdown our readWatcher... if parser has anything left, we don't care!
        if (!$this->readWatcher) {
            return;
        }

        list($data, $opcode) = $parseResult;

        switch ($opcode) {
            case self::OP_CLOSE:
                if ($this->closedAt) {
                    unset($this->closeTimeout);
                    $this->unloadClient();
                } else {
                    if (\strlen($data) < 2) {
                        return; // invalid close reason
                    }
                    $code = current(unpack('S', substr($data, 0, 2)));
                    $reason = substr($data, 2);

                    @stream_socket_shutdown($this->socket, STREAM_SHUT_RD);
                    \Amp\cancel($this->readWatcher);
                    $this->readWatcher = null;
                    $this->doClose($code, $reason);
                }
                break;

            case self::OP_PING:
                $this->compile($data, self::OP_PONG);
                break;

            case self::OP_PONG:
                // We need a min() here, else someone might just send a pong frame with a very high pong count and leave TCP connection in open state...
                $this->pongCount = min($this->pingCount, $data);
                break;
        }
    }

    private function onParsedData(array $parseResult) {
        if ($this->closedAt) {
            return;
        }

        $this->lastDataReadAt = $this->now;

        list($data, $terminated) = $parseResult;

        if (!$this->msgPromisor) {
            $this->msgPromisor = new Deferred;
            $msg = new Message($this->msgPromisor->promise());
            \Amp\resolve($this->tryAppOnData($msg));
        }

        $this->msgPromisor->update($data);
        if ($terminated) {
            $this->msgPromisor->succeed();
            $this->msgPromisor = null;
        }

        $this->messagesRead += $terminated;
    }

    private function tryAppOnData(Message $msg) {
        $gen = $this->application->onData($msg);
        if ($gen instanceof \Generator) {
            yield \Amp\resolve($gen);
        }
    }

    private function onParsedError(array $parseResult) {
        // something went that wrong that we had to shutdown our readWatcher... if parser has anything left, we don't care!
        if (!$this->readWatcher) {
            return;
        }

        list($msg, $code) = $parseResult;

        if ($code) {
            if ($this->closedAt || $code == Code::PROTOCOL_ERROR) {
                @stream_socket_shutdown($this->socket, STREAM_SHUT_RD);
                \Amp\cancel($this->readWatcher);
                $this->readWatcher = null;
            }

            if (!$this->closedAt) {
                $this->doClose($code, $msg);
            }
        }
    }

    public function onReadable($watcherId, $socket) {
        $data = @fread($socket, 8192);

        if ($data != "") {
            $this->lastReadAt = $this->now;
            $this->bytesRead += \strlen($data);
            $this->framesRead += $this->parser->send($data);
        } elseif (!is_resource($socket) || @feof($socket)) {
            if (!$this->closedAt) {
                $this->closedAt = $this->now;
                $code = Code::ABNORMAL_CLOSE;
                $reason = "Client closed underlying TCP connection";
                \Amp\resolve($this->tryAppOnClose($code, $reason));
            } else {
                unset($this->closeTimeout);
            }

            $this->unloadClient();
        }
    }

    public function onWritable($watcherId, $socket) {
        $bytes = @fwrite($socket, $this->writeBuffer);
        $this->bytesSent += $bytes;

        if ($bytes != \strlen($this->writeBuffer)) {
            $this->writeBuffer = substr($this->writeBuffer, $bytes);
        } elseif ($bytes == 0 && $this->closedAt && (!is_resource($socket) || @feof($socket))) {
            // usually read watcher cares about aborted TCP connections, but when
            // $this->closedAt is true, it might be the case that read watcher
            // is already cancelled and we need to ensure that our writing promise
            // is fulfilled in unloadClient() with a failure
            unset($this->closeTimeout);
            $this->unloadClient();
        } else {
            $this->framesSent++;
            $this->writeDeferred->succeed();
            if ($this->writeControlQueue) {
                $this->writeBuffer = array_shift($this->writeControlQueue);
                $this->lastSentAt = $this->now;
                $this->writeDeferred = array_shift($this->writeDeferredControlQueue);
            } elseif ($this->closedAt) {
                @stream_socket_shutdown($socket, STREAM_SHUT_WR);
                \Amp\cancel($watcherId);
                $this->writeWatcher = null;
                $this->writeBuffer = "";
            } elseif ($this->writeDataQueue) {
                $this->writeBuffer = array_shift($this->writeDataQueue);
                $this->lastDataSentAt = $this->now;
                $this->lastSentAt = $this->now;
                $this->writeDeferred = array_shift($this->writeDeferredDataQueue);
            } else {
                $this->writeBuffer = "";
                \Amp\disable($watcherId);
            }
        }
    }

    private function compile($msg, $opcode, $fin = true) {
        $frameInfo = ["msg" => $msg, "rsv" => 0b000, "fin" => $fin, "opcode" => $opcode];

        // @TODO filter mechanism …?! (e.g. gzip)
        foreach ($this->builder as $gen) {
            $gen->send($frameInfo);
            $gen->send(null);
            $frameInfo = $gen->current();
        }

        return $this->write($frameInfo);
    }

    private function write($frameInfo) {
        if ($this->closedAt) {
            return new Failure(new ClientException);
        }

        $msg = $frameInfo["msg"];
        $len = \strlen($msg);

        $w = chr(($frameInfo["fin"] << 7) | ($frameInfo["rsv"] << 4) | $frameInfo["opcode"]);

        if ($len > 0xFFFF) {
            $w .= "\xFF" . pack('J', $len);
        } elseif ($len > 0x7D) {
            $w .= "\xFE" . pack('n', $len);
        } else {
            $w .= chr($len | 0x80);
        }

        $mask = pack('N', mt_rand(-0x7fffffff - 1, 0x7fffffff)); // this is not a CSPRNG, but good enough for our use cases

        $w .= $mask;
        $w .= $msg ^ str_repeat($mask, ($len + 3) >> 2);

        \Amp\enable($this->writeWatcher);
        if ($this->writeBuffer != "") {
            if ($frameInfo["opcode"] >= 0x8) {
                $this->writeControlQueue[] = $w;
                $deferred = $this->writeDeferredControlQueue[] = new Deferred;
            } else {
                $this->writeDataQueue[] = $w;
                $deferred = $this->writeDeferredDataQueue[] = new Deferred;
            }
        } else {
            $this->writeBuffer = $w;
            $deferred = $this->writeDeferred = new Deferred;
        }

        return $deferred->promise();
    }

    // just a dummy builder ... no need to really use it
    private function defaultBuilder() {
        $yield = yield;
        while (1) {
            $data = [];
            $frameInfo = $yield;
            $data[] = $yield["msg"];

            while (($yield = yield) !== null); {
                $data[] = $yield;
            }

            $msg = count($data) == 1 ? $data[0] : implode($data);
            $yield = (yield $msg . $frameInfo);
        }
    }

    public function send($data, $binary = false) {
        $this->messagesSent++;

        $opcode = $binary ? self::OP_BIN : self::OP_TEXT;
        assert($binary || preg_match("//u", $data), "non-binary data needs to be UTF-8 compatible");

        if (\strlen($data) > 1.5 * $this->autoFrameSize) {
            $len = \strlen($data);
            $slices = ceil($len / $this->autoFrameSize);
            $frames = str_split($data, ceil($len / $slices));
            $data = array_pop($frames);
            foreach ($frames as $frame) {
                $this->compile($frame, $opcode, false);
                $opcode = self::OP_CONT;
            }
        }
        return $this->compile($data, $opcode);
    }

    public function sendBinary($data) {
        return $this->send($data, true);
    }

    public function close($code = Code::NORMAL_CLOSE, $reason = "") {
        $this->doClose($code, $reason);
    }

    public function getInfo() {
        return [
            'bytes_read'    => $this->bytesRead,
            'bytes_sent'    => $this->bytesSent,
            'frames_read'   => $this->framesRead,
            'frames_sent'   => $this->framesSent,
            'messages_read' => $this->messagesRead,
            'messages_sent' => $this->messagesSent,
            'connected_at'  => $this->connectedAt,
            'closed_at'     => $this->closedAt,
            'last_read_at'  => $this->lastReadAt,
            'last_sent_at'  => $this->lastSentAt,
            'last_data_read_at'  => $this->lastDataReadAt,
            'last_data_sent_at'  => $this->lastDataSentAt,
        ];
    }

    private function timeout() {
        $this->now = $now = time();

        if ($this->closeTimeout < $now && $this->closedAt) {
            $this->unloadClient();
            unset($this->closeTimeout);
        }
    }

    /**
     * A stateful generator websocket frame parser
     *
     * @param callable $emitCallback A callback to receive parser event emissions
     * @param array $options Optional parser settings
     * @return \Generator
     */
    static public function parser(callable $emitCallback, array $options = []) {
        $emitThreshold = isset($options["threshold"]) ? $options["threshold"] : 32768;
        $maxFrameSize = isset($options["max_frame_size"]) ? $options["max_frame_size"] : PHP_INT_MAX;
        $maxMsgSize = isset($options["max_msg_size"]) ? $options["max_msg_size"] : PHP_INT_MAX;
        $textOnly = isset($options["text_only"]) ? $options["text_only"] : false;
        $doUtf8Validation = $validateUtf8 = isset($options["validate_utf8"]) ? $options["validate_utf8"] : false;

        $dataMsgBytesRecd = 0;
        $nextEmit = $emitThreshold;
        $dataArr = [];

        $buffer = yield;
        $bufferSize = \strlen($buffer);
        $frames = 0;

        while (1) {
            $frameBytesRecd = 0;
            $payloadReference = '';

            while ($bufferSize < 2) {
                $buffer .= (yield $frames);
                $bufferSize = \strlen($buffer);
                $frames = 0;
            }

            $firstByte = ord($buffer);
            $secondByte = ord($buffer[1]);

            $buffer = substr($buffer, 2);
            $bufferSize -= 2;

            $fin = (bool)($firstByte & 0b10000000);
            // $rsv = ($firstByte & 0b01110000) >> 4; // unused (let's assume the bits are all zero)
            $opcode = $firstByte & 0b00001111;
            $isMasked = (bool)($secondByte & 0b10000000);
            $maskingKey = null;
            $frameLength = $secondByte & 0b01111111;

            $isControlFrame = $opcode >= 0x08;
            if ($validateUtf8 && $opcode !== self::OP_CONT && !$isControlFrame) {
                $doUtf8Validation = $opcode === self::OP_TEXT;
            }

            if ($frameLength === 0x7E) {
                while ($bufferSize < 2) {
                    $buffer .= (yield $frames);
                    $bufferSize = \strlen($buffer);
                    $frames = 0;
                }

                $frameLength = unpack('n', $buffer[0] . $buffer[1])[1];
                $buffer = substr($buffer, 2);
                $bufferSize -= 2;
            } elseif ($frameLength === 0x7F) {
                while ($bufferSize < 8) {
                    $buffer .= (yield $frames);
                    $bufferSize = \strlen($buffer);
                    $frames = 0;
                }

                $lengthLong32Pair = unpack('N2', substr($buffer, 0, 8));
                $buffer = substr($buffer, 8);
                $bufferSize -= 8;

                if (PHP_INT_MAX === 0x7fffffff) {
                    if ($lengthLong32Pair[1] !== 0 || $lengthLong32Pair[2] < 0) {
                        $code = Code::MESSAGE_TOO_LARGE;
                        $errorMsg = 'Payload exceeds maximum allowable size';
                        break;
                    }
                    $frameLength = $lengthLong32Pair[2];
                } else {
                    $frameLength = ($lengthLong32Pair[1] << 32) | $lengthLong32Pair[2];
                    if ($frameLength < 0) {
                        $code = Code::PROTOCOL_ERROR;
                        $errorMsg = 'Most significant bit of 64-bit length field set';
                        break;
                    }
                }
            }

            if ($frameLength > 0 && $isMasked) {
                $code = Code::PROTOCOL_ERROR;
                $errorMsg = 'Payload must not be masked';
                break;
            } elseif ($isControlFrame) {
                if (!$fin) {
                    $code = Code::PROTOCOL_ERROR;
                    $errorMsg = 'Illegal control frame fragmentation';
                    break;
                } elseif ($frameLength > 125) {
                    $code = Code::PROTOCOL_ERROR;
                    $errorMsg = 'Control frame payload must be of maximum 125 bytes or less';
                    break;
                }
            } elseif (($opcode === 0x00) === ($dataMsgBytesRecd === 0)) {
                // We deliberately do not accept a non-fin empty initial text frame
                $code = Code::PROTOCOL_ERROR;
                if ($opcode === 0x00) {
                    $errorMsg = 'Illegal CONTINUATION opcode; initial message payload frame must be TEXT or BINARY';
                } else {
                    $errorMsg = 'Illegal data type opcode after unfinished previous data type frame; opcode MUST be CONTINUATION';
                }
                break;
            } elseif ($maxFrameSize && $frameLength > $maxFrameSize) {
                $code = Code::MESSAGE_TOO_LARGE;
                $errorMsg = 'Payload exceeds maximum allowable frame size';
                break;
            } elseif ($maxMsgSize && ($frameLength + $dataMsgBytesRecd) > $maxMsgSize) {
                $code = Code::MESSAGE_TOO_LARGE;
                $errorMsg = 'Payload exceeds maximum allowable message size';
                break;
            } elseif ($textOnly && $opcode === 0x02) {
                $code = Code::UNACCEPTABLE_TYPE;
                $errorMsg = 'BINARY opcodes (0x02) not accepted';
                break;
            }

            if ($isMasked) {
                while ($bufferSize < 4) {
                    $buffer .= (yield $frames);
                    $bufferSize = \strlen($buffer);
                    $frames = 0;
                }

                $maskingKey = substr($buffer, 0, 4);
                $buffer = substr($buffer, 4);
                $bufferSize -= 4;
            }

            while (1) {
                if ($bufferSize + $frameBytesRecd >= $frameLength) {
                    $dataLen = $frameLength - $frameBytesRecd;
                } else {
                    $dataLen = $bufferSize;
                }

                if ($isControlFrame) {
                    $payloadReference =& $controlPayload;
                } else {
                    $payloadReference =& $dataPayload;
                    $dataMsgBytesRecd += $dataLen;
                }

                $payloadReference .= substr($buffer, 0, $dataLen);
                $frameBytesRecd += $dataLen;

                $buffer = substr($buffer, $dataLen);
                $bufferSize -= $dataLen;

                if ($frameBytesRecd == $frameLength) {
                    break;
                }

                // if we want to validate UTF8, we must *not* send incremental mid-frame updates because the message might be broken in the middle of an utf-8 sequence
                // also, control frames always are <= 125 bytes, so we never will need this as per https://tools.ietf.org/html/rfc6455#section-5.5
                if (!$isControlFrame && $dataMsgBytesRecd >= $nextEmit) {
                    if ($isMasked) {
                        $payloadReference ^= str_repeat($maskingKey, ($frameBytesRecd + 3) >> 2);
                        // Shift the mask so that the next data where the mask is used on has correct offset.
                        $maskingKey = substr($maskingKey . $maskingKey, $frameBytesRecd % 4, 4);
                    }

                    if ($dataArr) {
                        $dataArr[] = $payloadReference;
                        $payloadReference = implode($dataArr);
                        $dataArr = [];
                    }

                    if ($doUtf8Validation) {
                        $string = $payloadReference;
                        for ($i = 0; !preg_match('//u', $payloadReference) && $i < 8; $i++) {
                            $payloadReference = substr($payloadReference, 0, -1);
                        }
                        if ($i == 8) {
                            $code = Code::INCONSISTENT_FRAME_DATA_TYPE;
                            $errorMsg = 'Invalid TEXT data; UTF-8 required';
                            break;
                        }

                        $emitCallback([self::DATA, $payloadReference, false]);
                        $payloadReference = $i > 0 ? substr($string, -$i) : '';
                    } else {
                        $emitCallback([self::DATA, $payloadReference, false]);
                        $payloadReference = '';
                    }

                    $frameLength -= $frameBytesRecd;
                    $nextEmit = $dataMsgBytesRecd + $emitThreshold;
                    $frameBytesRecd = 0;
                }

                $buffer .= (yield $frames);
                $bufferSize = \strlen($buffer);
                $frames = 0;
            }

            if ($isMasked) {
                // This is memory hungry but it's ~70x faster than iterating byte-by-byte
                // over the masked string. Deal with it; manual iteration is untenable.
                $payloadReference ^= str_repeat($maskingKey, ($frameLength + 3) >> 2);
            }

            if ($fin || $dataMsgBytesRecd >= $emitThreshold) {
                if ($isControlFrame) {
                    $emit = [self::CONTROL, $payloadReference, $opcode];
                } else {
                    if ($dataArr) {
                        $dataArr[] = $payloadReference;
                        $payloadReference = implode($dataArr);
                        $dataArr = [];
                    }

                    if ($doUtf8Validation) {
                        if ($fin) {
                            $i = preg_match('//u', $payloadReference) ? 0 : 8;
                        } else {
                            $string = $payloadReference;
                            for ($i = 0; !preg_match('//u', $payloadReference) && $i < 8; $i++) {
                                $payloadReference = substr($payloadReference, 0, -1);
                            }
                            if ($i > 0) {
                                $dataArr[] = substr($string, -$i);
                            }
                        }
                        if ($i == 8) {
                            $code = Code::INCONSISTENT_FRAME_DATA_TYPE;
                            $errorMsg = 'Invalid TEXT data; UTF-8 required';
                            break;
                        }
                    }

                    $emit = [self::DATA, $payloadReference, $fin];

                    if ($fin) {
                        $dataMsgBytesRecd = 0;
                    }
                    $nextEmit = $dataMsgBytesRecd + $emitThreshold;
                }

                $emitCallback($emit);
            } else {
                $dataArr[] = $payloadReference;
            }

            $frames++;
        }

        // An error occurred...
        // stop parsing here ...
        $emitCallback([self::ERROR, $errorMsg, $code]);
        yield $frames;
        while (1) {
            yield 0;
        }
    }
}