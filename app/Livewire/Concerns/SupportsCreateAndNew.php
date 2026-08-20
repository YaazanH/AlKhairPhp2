<?php

namespace App\Livewire\Concerns;

use BadMethodCallException;

trait SupportsCreateAndNew
{
    public function saveAndNew(string $saveMethod = 'save', string $createMethod = 'openCreateModal'): void
    {
        if (! method_exists($this, $saveMethod)) {
            throw new BadMethodCallException("Missing save method [{$saveMethod}].");
        }

        if (! method_exists($this, $createMethod)) {
            throw new BadMethodCallException("Missing create method [{$createMethod}].");
        }

        $errorCount = method_exists($this, 'getErrorBag') ? $this->getErrorBag()->count() : 0;

        $this->{$saveMethod}();

        foreach (['showDuplicateStudentModal', 'showDuplicateParentModal'] as $duplicateModalProperty) {
            if (property_exists($this, $duplicateModalProperty) && $this->{$duplicateModalProperty}) {
                return;
            }
        }

        if (method_exists($this, 'getErrorBag') && $this->getErrorBag()->count() > $errorCount) {
            return;
        }

        $this->{$createMethod}();
    }
}
