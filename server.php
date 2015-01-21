<?php

while (true) {
    $listenSocket = socket_create_listen(9999, 1);
    echo "Listening on port 9999 for connection...\n";
    $r = $w = $e = array($listenSocket);
    $n = socket_select($r, $w, $e, 120);
    $clientSocket = ($n ==1) ? socket_accept($listenSocket) : null;
    socket_close($listenSocket);
    echo "Connection!\n";

    define('GREET', 0);
    define('FROM', 1);
    define('RCPT', 2);
    define('DATA', 3);
    define('MSG', 4);

    $readBuffer = '';
    $writeBuffer = "220 JiffyMail Hello [192.168.1.234]\r\n";
    $active = true;
    $step = GREET;

    $from = "";
    $to   = "";
    $data = "";

    $idleStart = time();
    while(true) {
        $r = $w = $e = array($clientSocket);
        if (socket_select($r, $w, $e, 60)) {
            if ($r) {
                // read from socket
                $readBuffer .= socket_read($clientSocket, 128);
            }
            if ($w) {
                if ($writeBuffer) {
                    // write to socket
                    $written = socket_write($clientSocket, $writeBuffer);
                    $writeBuffer = substr($writeBuffer, $written);
                    $idleStart = time();
                } else if ($active) {
                    $now = time();
                    $idleTime = $now - $idleStart;
                    if ($idleTime > 10) {
                        // exit if nothing happens for 10 seconds
                        break;
                    } else if ($idleTime > 2) {
                        // start napping when client is too slow
                        sleep(1);
                    }
                } else {
                    break;
                }
            }
            if ($e) {
                // break on exception
                break;
            }
            if ($readBuffer) {
                // check if we've received a whole command
                switch ($step) {
                    case GREET:
                        if (preg_match('/([EHLO|HELO]+) (.+)[\r\n]+/', $readBuffer, $matches)) {
                            $readBuffer   = substr($readBuffer, strlen($matches[0]));
                            $args         = $matches[2];
                            $writeBuffer .= sprintf('250 OK - Hello %s, nice to meet you!', trim($args))."\r\n";
                            $step++;
                        }
                        break;
                    case FROM:
                        if (preg_match('/MAIL FROM: ?(.+)[\r\n]+/', $readBuffer, $matches)) {
                            $readBuffer   = substr($readBuffer, strlen($matches[0]));
                            $args         = $matches[1];
                            $writeBuffer .= sprintf('250 OK - Sender OK', $args)."\r\n";
                            $from = $args;
                            $step++;
                        }
                        break;
                    case RCPT:
                        if (preg_match('/RCPT TO: ?(.+)[\r\n]+/', $readBuffer, $matches)) {
                            $readBuffer   = substr($readBuffer, strlen($matches[0]));
                            $args         = $matches[1];
                            $writeBuffer .= "250 OK - Recipient OK\n";
                            $to = $args;
                            $step++;
                        }
                        break;
                    case DATA:
                        if (preg_match('/DATA[\r\n]+/', $readBuffer, $matches)) {
                            $readBuffer   = substr($readBuffer, strlen($matches[0]));
                            $writeBuffer .= "354 End data with <CR><LF>.<CR><LF>\r\n";
                            $step++;
                        }
                        break;
                    case MSG:
                        if (preg_match('/[\r\n]+(\.[\r\n]+)/', $readBuffer, $matches)) {
                            $data = substr($readBuffer, 0, strlen($readBuffer) - strlen($matches[1]));
                            $readBuffer   = "";
                            $writeBuffer .= sprintf("250 OK - Queued as %s\n", uniqid());
                            $step++;
                            if (preg_match('/subject: ?(.+)[\r\n]+/', $data, $matches)) {
                                $subject = $matches[1];
                                $body = substr($data, strlen($matches[0]), strlen($data));
                            } else {
                                $body = $data;
                            }
                            echo "From: ".$from ."\n";
                            echo "To: ".$to . "\n";
                            if ($subject) {
                                echo "Subject: ".$subject."\n";
                            }
                            echo "Body: ".$body. "\n";
                            $step = GREET;
                        }
                        break;
                }
            }
        } else {
            break;
        }
    }
    socket_close($clientSocket);
}
