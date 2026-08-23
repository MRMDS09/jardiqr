<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! app()->environment('testing')) {
            throw new RuntimeException(
                'تم إيقاف الاختبارات: بيئة التطبيق ليست الاختبارات.'
            );
        }

        if (config('database.default') !== 'sqlite') {
            throw new RuntimeException(
                'تم إيقاف الاختبارات: يجب استعمال سكل لايت وليس قاعدة التطوير.'
            );
        }

        if (config('database.connections.sqlite.database') !== ':memory:') {
            throw new RuntimeException(
                'تم إيقاف الاختبارات: يجب أن تكون قاعدة  سكل لايت داخل الذاكرة.'
            );
        }
    }
}
