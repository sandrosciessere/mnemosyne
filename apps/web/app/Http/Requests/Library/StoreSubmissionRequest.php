<?php

namespace App\Http\Requests\Library;

use App\Models\BookSubmission;
use Illuminate\Foundation\Http\FormRequest;

class StoreSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', BookSubmission::class) ?? false;
    }

    public function rules(): array
    {
        $maxKilobytes = (int) floor(config('mnemosyne.ingestion.max_upload_bytes') / 1024);

        return [
            // Real EPUB validation (magic bytes, container, safety) happens
            // in the worker validate stage — browser MIME types are hints.
            'epub' => [
                'required',
                'file',
                'extensions:epub',
                'max:'.$maxKilobytes,
                'mimetypes:application/epub+zip,application/zip,application/octet-stream',
            ],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
