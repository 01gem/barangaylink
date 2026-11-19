<?php
/**
 * BarangayLink - Test-only template storage in a temp folder.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * Creates sys_get_temp_dir()/capstone_tpl_<pid> with document_templates/ and
 * generated_documents/previews/, copies the seeded templates in (the real
 * files are only read), and sets BARANGAY_STORAGE_ROOT so the app resolves
 * storage there. Aborts unless the app really resolves to the temp folder.
 * Cleanup removes only tracked exact paths, then the (now empty) folders.
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

const TEST_STORAGE_SEEDED = ['barangay_clearance.docx', 'certificate_of_residency.docx', 'certificate_of_indigency.docx',
    'certificate_of_good_moral_character.docx', 'business_permit_endorsement.docx'];

final class TestStorage {
    public string $root;
    public string $templates;
    public string $previews;
    /** @var array<string, true> exact file paths this suite may delete */
    private array $files = [];
    /** @var list<string> folders created here, removed only when empty */
    private array $dirs = [];

    public function __construct(string $projectRoot) {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'capstone_tpl_' . getmypid();
        if (file_exists($this->root)) throw new RuntimeException('Test storage folder already exists: ' . $this->root);
        $this->templates = $this->root . DIRECTORY_SEPARATOR . 'document_templates';
        $generated = $this->root . DIRECTORY_SEPARATOR . 'generated_documents';
        $this->previews = $generated . DIRECTORY_SEPARATOR . 'previews';
        foreach ([$this->root, $this->templates, $generated, $this->previews] as $dir) {
            if (!mkdir($dir, 0700)) throw new RuntimeException('Cannot create test storage folder.');
            $this->dirs[] = $dir;
        }
        $real = $projectRoot . DIRECTORY_SEPARATOR . 'document_templates';
        foreach (TEST_STORAGE_SEEDED as $name) {
            $target = $this->templates . DIRECTORY_SEPARATOR . $name;
            if (!copy($real . DIRECTORY_SEPARATOR . $name, $target)) throw new RuntimeException('Cannot copy seeded template ' . $name);
            $this->track($target);
        }
        putenv('BARANGAY_STORAGE_ROOT=' . $this->root);
        $_ENV['BARANGAY_STORAGE_ROOT'] = $this->root; $_SERVER['BARANGAY_STORAGE_ROOT'] = $this->root;
        // Registered from inside a shutdown function so it runs after the
        // suite's own cleanup, which removes the files the suite created.
        register_shutdown_function(function (): void { register_shutdown_function([$this, 'cleanup']); });
    }

    /** Call after the app code is loaded: refuses to continue unless storage resolves to the temp folder. */
    public function assertActive(): void {
        $templates = realpath(document_storage_dir('templates'));
        $previews = realpath(document_storage_dir('previews'));
        if ($templates === false || strcasecmp($templates, (string)realpath($this->templates)) !== 0
            || $previews === false || strcasecmp($previews, (string)realpath($this->previews)) !== 0) {
            fwrite(STDERR, "ABORT: storage override is not active; refusing to touch real storage.\n");
            exit(2);
        }
    }

    /** Record a path inside the temp storage that this suite may delete. */
    public function track(string $path): string {
        $this->files[$path] = true;
        return $path;
    }

    public function template(string $name): string { return $this->templates . DIRECTORY_SEPARATOR . $name; }

    public function cleanup(): void {
        foreach (array_keys($this->files) as $path) if (is_file($path)) @unlink($path);
        $this->files = [];
        foreach (array_reverse($this->dirs) as $dir) {
            if (!is_dir($dir)) continue;
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..' && is_file($dir . DIRECTORY_SEPARATOR . $entry)) {
                    fwrite(STDERR, "NOTE: untracked test file left in temp storage: {$dir}" . DIRECTORY_SEPARATOR . "{$entry}\n");
                }
            }
            @rmdir($dir);
        }
        $this->dirs = [];
    }
}
