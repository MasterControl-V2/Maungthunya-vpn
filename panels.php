<?php
declare(strict_types=1);

if (basename($_SERVER['PHP_SELF']) === 'panels.php') {
    header('Content-Type: application/json');
}

$panels = [];

if (basename($_SERVER['PHP_SELF']) === 'panels.php') {
    $formatted = [];
    foreach ($panels as $name => $config) {
        $formatted[] = [
            'server_name' => $name,
            'location' => $config['location'],
            'type' => $config['type']
        ];
    }
    echo json_encode(['status' => 'success', 'panels' => $formatted, 'total' => count($formatted)], JSON_PRETTY_PRINT);
    exit;
}

return $panels;