<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // O Symfony injeta `Accept-Language: en-us` em toda Request de teste;
        // sem isto, a suíte passaria a receber mensagens em inglês.
        $this->withHeader('Accept-Language', 'pt-BR');
    }
}
