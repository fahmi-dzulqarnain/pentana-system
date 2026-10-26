<?php

namespace App\Services\BankStatements;

interface StatementTextExtractor
{
    /**
     * Return the statement's text with its column layout preserved.
     *
     * @throws StatementParseException when the PDF has no readable text.
     */
    public function extract(string $pdfPath): string;
}
