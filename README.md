# PDFMerger

PHP utility for concatenating PDFs page-by-page, built on the bundled FPDI 1.6.1
parser. Forked from the upstream `myokyawhtun/PDFMerger` so id-pal can carry
patches that the upstream project no longer accepts.

## Defensive resource guards (this fork)

The bundled FPDI 1.6.1 parser will, on certain malformed or hostile PDFs,
exhaust PHP's `memory_limit` while resolving objects or growing the read
buffer. Because `memory_limit` raises an `E_ERROR` (not catchable by
`\Throwable`), the PHP-FPM worker is terminated and the consuming code's
`try/catch (\Throwable)` block never runs.

This fork adds five layers of self-limiting guards inside
`Classes/fpdi/pdf_parser.php`, `Classes/fpdi/pdf_context.php` and
`Classes/fpdi/fpdi_pdf_parser.php`:

| Guard | Constant | Default | Where |
| --- | --- | --- | --- |
| Read recursion depth | `pdf_parser::MAX_READ_VALUE_DEPTH` | 256 | `_readValue` |
| Resolve recursion depth | `pdf_parser::MAX_RESOLVE_DEPTH` | 64 | `resolveObject` |
| Array element count | `pdf_parser::MAX_ARRAY_ELEMENTS` | 100 000 | `_readValue` |
| Dict entry count | `pdf_parser::MAX_DICT_ENTRIES` | 10 000 | `_readValue` |
| Parse memory delta | `pdf_parser::MAX_PARSE_MEMORY_BYTES` | 256 MB | `_assertBudget` |
| Buffer growth | `pdf_context::MAX_BUFFER_BYTES` | 16 MB | `increaseLength` |
| OBJREF cycles | _(no constant)_ | tracked per parse | `resolveObject` |
| `/Parent` / `/Kids` cycles | _(no constant)_ | tracked per call chain | `fpdi_pdf_parser` page-tree methods |

Any guard that fires throws a `pdf_parser_resource_exception` (which extends
`\RuntimeException`). Consumers catching `\Throwable` will see it; behaviour
is otherwise identical to upstream FPDI 1.6.1.

Override the constants in a subclass if a particular workload needs a wider
budget; do not edit them in place.

## Usage

```php
$pdf = new PDFMerger();
$pdf->addPDF('one.pdf', '1, 3, 4')
    ->addPDF('two.pdf', '1-2')
    ->addPDF('three.pdf', 'all')
    ->merge('file', 'output.pdf');
```

`merge()` output modes: `browser`, `download`, `string`, `file`.

## Tests

```
composer install
vendor/bin/phpunit
```

The suite exercises each guard with synthetic PDFs built in-memory. A
regression slot exists at `tests/fixtures/poa_6102671.pdf` for the production
POA that triggered the original incident; the file is `.gitignore`d for data
protection and the test is skipped when it isn't present.
