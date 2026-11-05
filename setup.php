<?php
// The old pages moved: Network Setup is now the Settings lists, the Activity Log is Events.
$tabs = ['zones' => 'zones', 'odbs' => 'odbs', 'types' => 'types', 'speeds' => 'speeds'];
header('Location: catalog.php?k=' . ($tabs[$_GET['tab'] ?? ''] ?? 'zones'));
