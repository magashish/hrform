<?php

namespace App\Services\Files;

use App\Enums\ScanStatus;
use Throwable;

/**
 * Streams a file to a clamd daemon using the INSTREAM command.
 */
class ClamAvScanner implements FileScanner
{
    public function scan(string $path): ScanStatus
    {
        try {
            $socket = $this->connect();
            fwrite($socket, "zINSTREAM\0");

            $file = fopen($path, 'rb');
            if ($file === false) {
                return ScanStatus::Error;
            }
            while (! feof($file)) {
                $chunk = fread($file, 8192);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                fwrite($socket, pack('N', strlen($chunk)).$chunk);
            }
            fclose($file);
            fwrite($socket, pack('N', 0));

            $response = trim((string) stream_get_contents($socket));
            fclose($socket);

            return match (true) {
                str_ends_with($response, 'OK') => ScanStatus::Clean,
                str_contains($response, 'FOUND') => ScanStatus::Infected,
                default => ScanStatus::Error,
            };
        } catch (Throwable) {
            return ScanStatus::Error;
        }
    }

    public function name(): string
    {
        return 'clamav';
    }

    /**
     * @return resource
     */
    private function connect()
    {
        $timeout = (int) config('portal.scanner.timeout', 30);
        $target = config('portal.scanner.clamav_socket')
            ?: 'tcp://'.config('portal.scanner.clamav_host').':'.config('portal.scanner.clamav_port');

        $socket = @stream_socket_client((string) $target, $errno, $errstr, $timeout);
        if ($socket === false) {
            throw new \RuntimeException('clamd unreachable');
        }
        stream_set_timeout($socket, $timeout);

        return $socket;
    }
}
