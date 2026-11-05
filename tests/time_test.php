<?php
require_once __DIR__ . '/../backend/lib/AppTime.php';
$failures = 0;
function checkTime(string $label, bool $result): void { global $failures; echo ($result ? 'PASS ' : 'FAIL ') . $label . PHP_EOL; $failures += !$result; }
AppTime::setDisplayZone('Europe/London');
checkTime('winter display converts stored Nairobi time', AppTime::format('Y-m-d H:i', '2026-11-05 12:00:00') === '2026-11-05 09:00');
checkTime('summer display follows daylight savings', AppTime::format('H:i', '2026-07-05 12:00:00') === '10:00');
checkTime('ticket local time converts to storage', AppTime::toStorage('2026-11-05T09:00') === '2026-11-05 12:00:00');
checkTime('local day filter starts at local midnight', AppTime::dayBoundary('2026-11-05') === '2026-11-05 03:00:00');
checkTime('DST day ends at next local midnight', AppTime::dayBoundary('2026-03-29', true) === '2026-03-30 02:00:00');
try { AppTime::toStorage('2026-02-31T10:00'); checkTime('invalid calendar date refused', false); } catch (InvalidArgumentException $e) { checkTime('invalid calendar date refused', true); }
try { AppTime::toStorage('2026-03-29T01:30'); checkTime('nonexistent DST time refused', false); } catch (InvalidArgumentException $e) { checkTime('nonexistent DST time refused', true); }
date_default_timezone_set('America/New_York');
checkTime('stored timestamps ignore execution timezone', AppTime::timestamp('1970-01-01 03:00:00') === 0);
exit($failures ? 1 : 0);
