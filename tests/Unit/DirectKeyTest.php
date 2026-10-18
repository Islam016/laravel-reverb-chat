<?php

namespace Tests\Unit;

use App\Models\Conversation;
use App\Models\User;
use PHPUnit\Framework\TestCase;

class DirectKeyTest extends TestCase
{
    public function test_direct_key_is_the_same_whoever_starts_the_chat(): void
    {
        $a = (new User)->forceFill(['id' => 7]);
        $b = (new User)->forceFill(['id' => 3]);

        $this->assertSame('3:7', Conversation::directKey($a, $b));
        $this->assertSame('3:7', Conversation::directKey($b, $a));
    }
}
