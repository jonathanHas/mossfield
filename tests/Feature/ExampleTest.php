<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * `/` is not a page: a closed system sends visitors to login (or, once
     * signed in, the dashboard). The stock welcome view is gone.
     */
    public function test_the_root_url_sends_visitors_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }
}
