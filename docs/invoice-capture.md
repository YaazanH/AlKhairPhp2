# Local receipt vision and invoice review

Use the existing original-invoice attachment field when preparing a new expense invoice. Selecting a PDF, JPEG, PNG, or WebP temporarily replaces the invoice popup with a separate reading/review popup. **Accept / قبول** replaces the whole draft with that document's reviewed details: unread fields become blank, a missing discount becomes zero, previous items and notes are cleared, and any unfinished item edit is reset. Acceptance works without editing any captured field, including when some values are missing. Incomplete rows are kept for completion in the invoice form. Uploading another file or choosing **Decline / رفض** (or closing the review) keeps the current form fields intact. Both choices keep the uploaded attachment. The existing Save/Finalise action is still required to save the invoice and update accounting.

When editing an existing invoice, including through Financial Settings → Edit Invoice, the upload is attachment-only: it validates the replacement file without starting OCR or opening the review popup. All invoice fields, items, notes and manual edits in progress stay unchanged. The existing Save action stores the replacement attachment.

The default engine is **Qwen3.5 9B through local Ollama**. It reads rendered pages directly, including handwriting, marginal notes, stamps, mixed Arabic/English, and metadata outside a fixed template. Even PDFs with embedded text are rendered so handwritten additions are visible, at up to 3200 pixels on the longest edge. JPEG orientation is corrected before cropping. Each page produces a 1280-pixel overview and four overlapping detail views up to 1536 pixels, encoded as lossless PNGs. Small sources can be enlarged by up to 3x; this does not recover detail absent from the original. Views are labelled by page and position so overlapping rows are counted once. No receipt is sent to an external API, and no API key is required.

The model returns structured fields and item rows, which are validated before reaching the form. Uncertain fields are left blank; incomplete, uncertain, or arithmetically inconsistent rows are shown in the editable review table; unknown values remain blank when accepted. Totals, duplicate supplier/invoice numbers, currencies, and approved amounts are checked. The model is instructed to treat receipt text as data, leave unreadable values unknown, and avoid guessing missing quantities or prices. These checks cannot guarantee transcription accuracy: compare suggestions with the original, especially handwriting and numerals. Actual accuracy must be evaluated on representative receipts.

Handwritten Arabic zeros can look like dots or connected short strokes. The reader is instructed to inspect the enlarged views, distinguish these marks from five, decimals and printed grid lines, and preserve visible trailing zeros. No denoising or thresholding removes small ink marks. Unclear zero counts must remain unknown with a review note; arithmetic must never be used to invent digits.

The separate review popup always uses the invoice-style, full-width item table, including for captures with no warnings. Rows display their values until Edit is pressed. Edit opens the row inputs with standard Save and Delete icon buttons; Delete is available inside the edit row only. The main invoice table uses the same button pattern. Uncertain rows retain their names but leave their numbers blank for manual entry. Reviewers can correct the item name, quantity and unit price; each line total updates automatically as quantity × unit price, rounded to two decimal places. Line totals cannot be entered separately. There are no add-item, discount, or manual-grand-total controls below this table. Saving a row returns it to display mode; Accept also includes the current row edits without requiring a separate row save. Rows can be removed while editing; missing items and discount changes can be entered in the main invoice form after accepting the review. The captured discount is retained, or defaults to zero if unread, and never comes from the previous document.

The review shows the financial transaction's currency, falling back to the accepted request currency when no posted transaction exists. Unit price inputs carry the currency symbol beside the field; displayed prices and totals use the shared finance formatter with that currency's symbol and decimal places. A different currency detected on the receipt still produces a warning; no conversion is applied. All numeric fields and results align right in Arabic while keeping numeric content left-to-right.

Accept transfers the current rows without requiring edits or complete values. Blank names and numbers, zero quantities, and an empty table can be accepted; unknown numbers stay blank rather than becoming zero. Arabic/Persian numerals and grouped numbers are normalized. Server checks still reject malformed rows, nonnumeric values and excessive amounts. Items do not need to match an uncertain OCR grand total. The main invoice form calculates its totals and requires complete item names, positive quantities and nonnegative prices when saving, along with its normal discount and approved-amount checks. Missing row values appear as dashes with inline validation errors after Save; they can be completed with the existing row edit action. Accept itself never saves an invoice or posts accounting entries. Decline discards corrections and preserves the original invoice form and attachment. Accepting a metadata-only capture also clears previous items; missing data must be entered before saving.

The current 9B model did not reliably read the trailing zeros in the supplied handwritten receipt, even with enlarged views. The implementation therefore keeps this local model and leaves uncertain numbers blank for manual completion before saving. Accepting a draft does not mark those readings as verified. Arithmetic checks detect inconsistencies; they cannot detect every consistently misread number.

Review notes follow the interface language. If the model returns explanatory text in another language, the application translates those notes through the same local model using a text-only request, with a 30-second maximum timeout. Receipt names and identifiers stay in their original language. Translations must preserve the notes' numbers and referenced receipt names; they cannot change invoice fields. Failed or still untranslated notes are replaced by a message in the interface language explaining that translation was unavailable. Other captured data remains available for review.

## Local setup

On macOS:

```sh
brew install ollama poppler
brew services start ollama
ollama pull qwen3.5:9b
```

The model download is approximately 6.6 GB. Use a machine with enough memory for the model and image context; GPU acceleration is recommended for responsive processing. On Linux or Windows, install Ollama using its official platform instructions and install Poppler. The application Dockerfile includes Poppler.

The application defaults are:

```dotenv
INVOICE_CAPTURE_ENGINE=vision
INVOICE_VISION_URL=http://127.0.0.1:11434
INVOICE_VISION_MODEL=qwen3.5:9b
INVOICE_VISION_TIMEOUT=120
```

Disable Ollama Cloud in `~/.ollama/server.json` with `"disable_ollama_cloud": true`, or set `OLLAMA_NO_CLOUD=1` in the Ollama service environment, then restart Ollama. The application rejects public endpoints, cloud model names, and HTTP redirects. Allowed service hosts are loopback, `host.docker.internal`, and the Docker service name `ollama`. Do not expose the model service publicly.

For Docker Desktop, run Ollama on the host for GPU access and set `INVOICE_VISION_URL=http://host.docker.internal:11434` in the application environment. Configure the host service to accept connections from the private Docker network. A Linux GPU deployment can use a private Ollama container with `INVOICE_VISION_URL=http://ollama:11434`; provision the same model in that container. Rebuilding the PHP image alone does not install the model.

Refresh Laravel's configuration cache after changing production environment values. Set PHP/proxy request timeouts above the 120-second inference window plus document preparation time and up to 30 seconds for note translation when needed. If the model is missing, unavailable, or times out, the invoice form returns with an explanatory message and keeps the attachment for manual entry. It never silently falls back to a weaker reader.

## Optional basic printed-text engine

`INVOICE_CAPTURE_ENGINE=tesseract` explicitly enables the earlier printed-text parser. It is not recommended for handwriting or scattered metadata. It requires Tesseract with `ara` and `eng`, plus Poppler. Executable path overrides remain available through `INVOICE_OCR_TESSERACT`, `INVOICE_OCR_PDFINFO`, `INVOICE_OCR_PDFTOTEXT`, and `INVOICE_OCR_PDFTOPPM`; `INVOICE_OCR_TESSDATA` can override language data location.

## Limits and storage

- The existing `IMAGE_UPLOAD_MAX_KB` limit applies (20 MB by default), with at most 3 PDF pages and 25 megapixels per image.
- Document preparation has a 45-second limit; model inference has its own configurable 120-second limit.
- Originals use the existing Livewire upload and invoice attachment lifecycle. Intermediate rendered pages are private under `storage/framework/cache/invoice-ocr` and are removed after preparation, including failures.
- Model image context lives in local memory. Ollama may keep the model loaded for five minutes between requests.
- Capture requires the existing expense review permission and access to the request's cashbox; it is available for invoice-mode expenses only.

## Verification

```sh
php -d memory_limit=512M vendor/bin/phpunit tests/Feature/InvoiceCaptureTest.php tests/Feature/InvoiceVisionTest.php tests/Feature/InvoiceOcrServiceTest.php tests/Unit/InvoiceDraftParserTest.php
npm run build
php artisan view:cache
```

Vision integration tests use deterministic local HTTP responses to verify page/detail association, schema validation, unknown values, and rejection of cloud processing. Pixel-level tests check that tiny ink marks survive detail preparation, including the overlap between crops; numeric validation tests check that ambiguous zeros are not filled in using arithmetic. Livewire tests verify unchanged and incomplete draft acceptance, required item values when saving, calculated line totals, currency symbols and precision, localized errors, Arabic digit normalization, and that declined corrections do not change the invoice. These tests do not measure handwriting recognition accuracy. Executable-dependent OCR tests skip when dependencies are unavailable. Validate actual model quality separately on sample receipts.

References: [Qwen3.5 model](https://ollama.com/library/qwen3.5), [Ollama vision](https://docs.ollama.com/capabilities/vision), [structured output](https://docs.ollama.com/capabilities/structured-outputs), [local-only configuration](https://docs.ollama.com/faq#how-do-i-disable-ollama-cloud-features).
