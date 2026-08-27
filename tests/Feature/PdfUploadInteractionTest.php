<?php

namespace Tests\Feature;

use Tests\TestCase;

class PdfUploadInteractionTest extends TestCase
{
    public function test_pdf_picker_locks_during_upload_and_after_success_but_unlocks_after_failure(): void
    {
        $script = file_get_contents(resource_path('js/app.js'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString("lockPdfUploadInput(event.target, 'uploading');", $script);
        $this->assertStringContainsString("document.addEventListener('livewire-upload-finish'", $script);
        $this->assertStringContainsString('if (!selectedFilesIncludePdf(event.target))', $script);
        $this->assertStringContainsString("lockPdfUploadInput(event.target, 'complete');", $script);
        $this->assertStringContainsString("['livewire-upload-error', 'livewire-upload-cancel']", $script);
        $this->assertStringContainsString('unlockPdfUploadInput(event.target);', $script);
        $this->assertStringContainsString("input[type='file'][data-pdf-upload-lock-state]", $styles);
        $this->assertStringContainsString("input[type='file'][data-pdf-upload-lock-state='complete']", $styles);
    }
}
