<?php

function test($command) {
    if (preg_match('/[\r\n]+(\.[\r\n]+)/', $command, $matches)) {
        print_r($matches);
        echo substr($command, 0, strlen($command) - strlen($matches[1]));
    } else {
        echo "no match";
    }
}

$commands = array(
    "Hello there how are you I like things\nTest\n.\n",
);

foreach ($commands as $command) {
    test($command);
}
