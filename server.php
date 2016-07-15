<?php

if (!isset($argv[1]) || !is_numeric($argv[1])) {
    echo "\nUsage:\n$ php server.php {port}\n\n";
    exit(1);
}

$port = $argv[1];

$listenSocket = socket_create_listen($port, 1);

if (!$listenSocket) {
    die("Failed to bind to $port");
}

define('KILL', -1);
define('GREET', 0);
define('LOGIN', 1);
define('USER', 2);
define('PASS', 3);
define('FROM', 4);
define('RCPT', 5);
define('DATA', 6);
define('MSG', 7);
define('LMTP', 10);
define('QUIT', 8);

while (true) {
    echo "Listening on port $port for connection...\n";
    $r = $w = $e = array($listenSocket);
    $n = socket_select($r, $w, $e, 120);
    $clientSocket = ($n ==1) ? socket_accept($listenSocket) : null;

    $pid = pcntl_fork();

    $host = '';
    $port = '';
    socket_getsockname($clientSocket, $host, $port);
    if ($pid == -1) {
        echo "Could not fork";
        continue;
    } else if ($pid) {
        // Parent process
        printf("Connection from %s:%d\n", $host, $port);
        continue;
    }

    $readBuffer = '';
    $writeBuffer = "220 JiffyMail Hello [192.168.1.234]\r\n";
    $active = true;
    $step = GREET;

    $from = "";
    $to   = "";
    $data = "";
    $user = "";
    $pass = "";

    $idleStart = time();
    while (true) {
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
                    if ($step === KILL && !$writeBuffer) {
                        break;
                    }
                    $idleStart = time();
                } else if ($active) {
                    $now = time();
                    $idleTime = $now - $idleStart;
                    if ($idleTime > 10) {
                        // exit if nothing happens for 10 seconds
                        printf("Timeout %s:%d, disconnecting\n", $host, $port);
                        $writeBuffer = "You take too long, goodbye!\n";
                        $step = KILL;
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
                    case LOGIN:
                        if (preg_match('/AUTH LOGIN[\r\n]+/', $readBuffer, $matches)) {
                            $readBuffer = substr($readBuffer, strlen($matches[0]));
                            $writeBuffer .= sprintf('334 %s;', base64_encode('Username:'))."\r\n";
                            $step++;
                        } else {
                            $step = FROM;
                        }
                        break;
                    case USER:
                        if (preg_match('/(.+)[\r\n]+/', $readBuffer, $matches)) {
                            $readBuffer   = substr($readBuffer, strlen($matches[0]));
                            $user         = $matches[1];
                            $writeBuffer .= sprintf('334 %s;', base64_encode('Password:'))."\r\n";
                            $step++;
                        }
                        break;
                    case PASS:
                        if (preg_match('/(.+)[\r\n]+/', $readBuffer, $matches)) {
                            $readBuffer   = substr($readBuffer, strlen($matches[0]));
                            $pass         = $matches[1];
                            $writeBuffer .= sprintf('235 Authentication succeeded', base64_encode('Password:'))."\r\n";
                            $step++;
                        }
                        break;
                    case FROM:
                        if (preg_match('/MAIL FROM: ?(.+)[\r\n]+/', $readBuffer, $matches)) {
                            $readBuffer   = substr($readBuffer, strlen($matches[0]));
                            $args         = $matches[1];
                            $writeBuffer .= sprintf('250 OK - Sender OK', $args)."\r\n";
                            $from = trim(trim($args), '<>');
                            $step++;
                        }
                        break;
                    case RCPT:
                        if (preg_match('/RCPT TO: ?(.+)[\r\n]+/', $readBuffer, $matches)) {
                            $readBuffer   = substr($readBuffer, strlen($matches[0]));
                            $args         = $matches[1];
                            $writeBuffer .= "250 OK - Recipient OK\n";
                            $to = trim(trim($args), '<>');
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
                            $readBuffer   = substr($readBuffer, strlen($matches[0]));
                            $writeBuffer .= sprintf("250 OK - Queued as %s\n", uniqid());

                            echo $writeBuffer;

                            $step++;
                        }
                        break;
                    case LMTP:
                        $user = ($user) ?: 'mailtrap';
                        $sendStream = sprintf(
                            "LHLO jiffy.services\r\nMAIL FROM:<%s>\r\nRCPT TO:<{$user}>\r\nDATA\r\n%s\r\n.\r\nQUIT\r\n",
                            $from,
                            $data
                        );

                        $sendr = stream_socket_client('unix:///var/spool/postfix/dovecot/dovecot-lmtp');
                        if (!$sendr) {
                            die("Could not open socket to Dovecot");
                        }
                        fwrite($sendr, $sendStream, strlen($sendStream));
                        while (!feof($sendr)) {
                            file_put_contents('mail.log', fgets($sendr, 1024), FILE_APPEND);
                        }
                        fclose($sendr);
                        $step++;
                        break;
                    case QUIT:
                        if (preg_match('/QUIT/', $readBuffer, $matches)) {
                            $readBuffer   = '';
                            $writeBuffer .= "221 Bye!\r\n";
                            $step = KILL;
                        }
                        break;
                }
            }
        } else {
            break;
        }
    }
    if ($clientSocket) {
        socket_close($clientSocket);
    }
}
