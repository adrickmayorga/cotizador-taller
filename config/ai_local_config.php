<?php
/**
 * SRNU CUSTOM · IA LOCAL
 * No contiene claves ni servicios de pago. Ollama se ejecuta en esta misma PC.
 */
return [
    'enabled' => true,
    'url' => 'http://127.0.0.1:11434',
    'model' => 'qwen3-vl:4b-instruct',
    'timeout' => 180,
    'max_pdf_pages' => 4,
    'max_text_chars' => 70000,
    'temperature' => 0.0,
];
