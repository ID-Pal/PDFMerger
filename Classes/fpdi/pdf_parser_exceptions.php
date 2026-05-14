<?php
/**
 * Exception types raised by the PDFMerger defensive resource guards.
 *
 * These are thrown when the bundled FPDI 1.6.1 parser detects runaway use of
 * memory, recursion, buffer growth, or object/page-tree cycles. Throwing a
 * regular {@see \RuntimeException} subclass allows the consuming application
 * to handle the failure with a normal try/catch -- PHP's `memory_limit` fatal
 * (E_ERROR) is not catchable by {@see \Throwable}, so the parser must bail
 * defensively before that fatal is reached.
 */

if (!class_exists('pdf_parser_resource_exception')) {
    class pdf_parser_resource_exception extends \RuntimeException
    {
    }
}
