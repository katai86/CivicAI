<?php
/**
 * Base provider with HTTP and config helpers.
 */
namespace CivicAI\Iot;

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../util.php';

abstract class AbstractProvider implements VirtualSensorProviderInterface {

  protected function httpGet(string $url, array $headers = [], int $timeout = 15): ?array {
    $ctx = stream_context_create([
      'http' => [
        'timeout' => $timeout,
        'ignore_errors' => true,
        'header' => implode("\r\n", array_merge(
          ['User-Agent: CivicAI/1.0'],
          $headers
        )),
      ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return null;
    $dec = json_decode($raw, true);
    return is_array($dec) ? $dec : null;
  }

  protected function getIotSetting(string $key): ?string {
    return get_module_setting('iot', $key);
  }
}
