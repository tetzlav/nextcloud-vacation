<?php

declare(strict_types=1);

namespace OCA\NextcloudVacation\Service;

use OCA\NextcloudVacation\AppInfo\Application;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use RuntimeException;

class PdfLogoService
{
    private const FOLDER = 'pdf';
    private const FILE = 'logo';
    private const MAX_BYTES = 2097152;
    private const MIME_CONFIG = 'pdf_logo_mime';

    public function __construct(
        private IAppData $appData,
        private IConfig $config
    ) {
    }

    public function saveUploadedFile(array $upload): void
    {
        $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            return;
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('The PDF logo upload failed.');
        }

        $path = (string)($upload['tmp_name'] ?? '');
        $size = (int)($upload['size'] ?? 0);
        if ($path === '' || !is_file($path) || $size <= 0 || $size > self::MAX_BYTES) {
            throw new RuntimeException('The PDF logo must be a PNG or JPEG image no larger than 2 MB.');
        }

        $image = @getimagesize($path);
        $mime = is_array($image) ? (string)($image['mime'] ?? '') : '';
        if (!in_array($mime, ['image/png', 'image/jpeg'], true)) {
            throw new RuntimeException('The PDF logo must be a PNG or JPEG image.');
        }
        if ((int)($image[0] ?? 0) > 6000 || (int)($image[1] ?? 0) > 3000) {
            throw new RuntimeException('The PDF logo dimensions are too large.');
        }
        if ($mime === 'image/jpeg' && (int)($image['channels'] ?? 3) !== 3) {
            throw new RuntimeException('CMYK JPEG logos are not supported. Please use PNG or an RGB JPEG image.');
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('The PDF logo could not be read.');
        }

        $folder = $this->folder();
        $file = $folder->fileExists(self::FILE)
            ? $folder->getFile(self::FILE)
            : $folder->newFile(self::FILE);
        $file->putContent($content);
        $this->config->setAppValue(Application::APP_ID, self::MIME_CONFIG, $mime);
    }

    public function remove(): void
    {
        try {
            $folder = $this->appData->getFolder(self::FOLDER);
            if ($folder->fileExists(self::FILE)) {
                $folder->getFile(self::FILE)->delete();
            }
        } catch (NotFoundException) {
        }

        $this->config->deleteAppValue(Application::APP_ID, self::MIME_CONFIG);
    }

    public function isConfigured(): bool
    {
        try {
            return $this->appData->getFolder(self::FOLDER)->fileExists(self::FILE);
        } catch (NotFoundException) {
            return false;
        }
    }

    public function dataUri(): ?string
    {
        try {
            $content = $this->appData->getFolder(self::FOLDER)->getFile(self::FILE)->getContent();
        } catch (NotFoundException) {
            return null;
        }

        $mime = $this->config->getAppValue(Application::APP_ID, self::MIME_CONFIG, 'image/png');
        if (!in_array($mime, ['image/png', 'image/jpeg'], true)) {
            $mime = 'image/png';
        }

        return 'data:' . $mime . ';base64,' . base64_encode($content);
    }

    private function folder(): mixed
    {
        try {
            return $this->appData->getFolder(self::FOLDER);
        } catch (NotFoundException) {
            return $this->appData->newFolder(self::FOLDER);
        }
    }
}
