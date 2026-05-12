<?php

/**
 * PHPUnit bootstrap. Pulls in the bundled FPDI/FPDF classes so the tests can
 * exercise the parser without going through composer autoload of the
 * non-namespaced legacy code.
 */

$classesDir = __DIR__ . '/../Classes';
$fpdiDir = $classesDir . '/fpdi';

require_once $fpdiDir . '/pdf_parser_exceptions.php';
require_once $fpdiDir . '/pdf_context.php';
require_once $fpdiDir . '/pdf_parser.php';
require_once $classesDir . '/fpdf/fpdf.php';
require_once $fpdiDir . '/fpdi_bridge.php';
require_once $fpdiDir . '/fpdf_tpl.php';
require_once $fpdiDir . '/fpdi_pdf_parser.php';
require_once $fpdiDir . '/fpdi.php';
require_once $classesDir . '/PDFMerger.php';
