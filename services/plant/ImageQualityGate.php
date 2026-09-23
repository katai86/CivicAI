<?php
/**
 * M18 – Image quality gate before AI inference.
 */
final class ImageQualityGate
{
    /** @return array{ok:bool,failure_state:?string,score:float,details:array<string,mixed>} */
    public static function check(string $imagePath, int $minWidth = 200, int $minHeight = 200): array
    {
        if (!is_file($imagePath) || !is_readable($imagePath)) {
            return ['ok' => false, 'failure_state' => 'INSUFFICIENT_IMAGE', 'score' => 0.0, 'details' => ['reason' => 'file_missing']];
        }
        $size = @filesize($imagePath);
        if ($size === false || $size < 4096) {
            return ['ok' => false, 'failure_state' => 'INSUFFICIENT_IMAGE', 'score' => 0.0, 'details' => ['reason' => 'file_too_small']];
        }
        $info = @getimagesize($imagePath);
        if ($info === false) {
            return ['ok' => false, 'failure_state' => 'INSUFFICIENT_IMAGE', 'score' => 0.0, 'details' => ['reason' => 'not_image']];
        }
        [$w, $h] = $info;
        if ($w < $minWidth || $h < $minHeight) {
            return ['ok' => false, 'failure_state' => 'INSUFFICIENT_IMAGE', 'score' => 0.0, 'details' => ['reason' => 'resolution_low', 'width' => $w, 'height' => $h]];
        }
        $score = min(1.0, ($w * $h) / (800 * 600));
        return ['ok' => true, 'failure_state' => null, 'score' => round($score, 3), 'details' => ['width' => $w, 'height' => $h, 'bytes' => $size]];
    }
}
