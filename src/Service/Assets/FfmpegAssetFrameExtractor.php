<?php

declare(strict_types=1);

namespace VertoAD\Service\Assets;

use VertoAD\Service\Archive\ArchiveCommandRunnerInterface;

final readonly class FfmpegAssetFrameExtractor
{
    private ArchiveCommandRunnerInterface $runner;

    public function __construct(
        ?ArchiveCommandRunnerInterface $runner = null,
        private string $binary = 'ffmpeg',
        private int $timeoutSeconds = 30,
    ) {
        if (trim($this->binary) === '' || $this->timeoutSeconds <= 0) {
            throw new \InvalidArgumentException('FFmpeg binary and timeout must be configured for video snapshots.');
        }

        $this->runner = $runner ?? new AssetProcessCommandRunner();
    }

    public function extract(string $sourceBytes, string $contentType, int $maxWidth, int $maxHeight): string
    {
        if (
            $sourceBytes === ''
            || !in_array($contentType, ['video/mp4', 'video/webm'], true)
            || $maxWidth <= 0
            || $maxHeight <= 0
        ) {
            throw new AssetSnapshotProcessingException('asset_video_source_invalid', 'Video snapshot source metadata is invalid.', false);
        }
        if (!$this->matchesContainer($sourceBytes, $contentType)) {
            throw new AssetSnapshotProcessingException(
                'asset_video_content_changed',
                'Video bytes no longer match the confirmed container type.',
                false,
            );
        }

        $filter = sprintf(
            'scale=w=min(%d\,iw):h=min(%d\,ih):force_original_aspect_ratio=decrease',
            $maxWidth,
            $maxHeight,
        );
        $result = $this->runner->run([
            $this->binary,
            '-hide_banner',
            '-loglevel',
            'error',
            '-nostats',
            '-protocol_whitelist',
            'pipe',
            '-i',
            'pipe:0',
            '-frames:v',
            '1',
            '-vf',
            $filter,
            '-f',
            'image2pipe',
            '-vcodec',
            'png',
            'pipe:1',
        ], $sourceBytes, $this->timeoutSeconds);
        if ($result->exitCode !== 0) {
            throw new AssetSnapshotProcessingException(
                'asset_video_frame_extract_failed',
                'FFmpeg could not extract a review frame from the video.',
                true,
            );
        }

        $image = @getimagesizefromstring($result->stdout);
        if (!is_array($image) || ($image['mime'] ?? null) !== 'image/png') {
            throw new AssetSnapshotProcessingException(
                'asset_video_frame_invalid',
                'FFmpeg returned an invalid PNG review frame.',
                true,
            );
        }

        return $result->stdout;
    }

    private function matchesContainer(string $bytes, string $contentType): bool
    {
        return $contentType === 'video/mp4'
            ? strlen($bytes) >= 12 && substr($bytes, 4, 4) === 'ftyp'
            : str_starts_with($bytes, "\x1A\x45\xDF\xA3");
    }
}
