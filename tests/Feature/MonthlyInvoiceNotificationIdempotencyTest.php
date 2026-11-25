<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use App\Services\OperationalNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MonthlyInvoiceNotificationIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('carevma_test', DB::connection()->getDatabaseName());
    }

    public function test_an_admin_receives_only_one_notification_per_invoice_and_next_invoice_notifies_again(): void
    {
        $admin = User::create([
            'name' => 'Invoice Admin', 'email' => 'invoice-admin@example.test',
            'password' => 'password', 'role' => 'admin',
        ]);
        $notifier = app(OperationalNotifier::class);
        $send = function (string $key) use ($notifier, $admin): void {
            $notifier->send($admin, 'Monthly invoice generated', 'Invoice created',
                'InvoiceMonthlyRun', '/admin/invoices', ['invoice_ids' => [123]], false, $key);
        };

        $send('invoice-monthly-created:123');
        $send('invoice-monthly-created:123');
        $send('invoice-monthly-created:123');
        $this->assertSame(1, Notification::where('user_id', $admin->id)
            ->where('type', 'InvoiceMonthlyRun')->count());

        // A new invoice ID (usually the next billing cycle) notifies exactly once.
        $send('invoice-monthly-created:456');
        $send('invoice-monthly-created:456');
        $this->assertSame(2, Notification::where('user_id', $admin->id)
            ->where('type', 'InvoiceMonthlyRun')->count());
    }
}
