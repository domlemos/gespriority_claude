<?php

namespace Tests\Unit;

use Database\Factories\RoleFactory;
use PHPUnit\Framework\TestCase;

/**
 * O slug `admin` dá bypass de visibilidade (`User::isAdmin()`); um papel
 * gerado ao acaso nunca pode recebê-lo, mesmo quando o Faker sorteia "Admin".
 */
class RoleFactoryTest extends TestCase
{
    public function test_nome_admin_nao_gera_slug_admin(): void
    {
        $this->assertNotSame('admin', RoleFactory::slugPara('Admin'));
        $this->assertNotSame('admin', RoleFactory::slugPara('ADMIN'));
        $this->assertSame('gerente-de-vendas', RoleFactory::slugPara('Gerente de Vendas'));
    }
}
