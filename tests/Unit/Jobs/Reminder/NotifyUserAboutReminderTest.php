<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Tests\TestCase;
use App\Models\User\User;
use App\Models\Account\Account;
use App\Models\Contact\Contact;
use App\Models\Contact\Reminder;
use App\Notifications\UserNotified;
use App\Notifications\UserReminded;
use Illuminate\Support\Facades\Event;
use App\Models\Contact\ReminderOutbox;
use Illuminate\Support\Facades\Notification;
use App\Jobs\Reminder\NotifyUserAboutReminder;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class NotifyUserAboutReminderTest extends TestCase
{
    use DatabaseTransactions;

    public function test_it_sends_a_reminder_to_a_user()
    {
        Notification::fake();

        Carbon::setTestNow(Carbon::create(2017, 1, 1, 7, 0, 0));

        $account = factory(Account::class)->create([
            'default_time_reminder_is_sent' => '07:00',
        ]);
        $contact = factory(Contact::class)->create(['account_id' => $account->id]);
        $user = factory(User::class)->create(['account_id' => $account->id]);
        $reminder = factory(Reminder::class)->create([
            'account_id' => $account->id,
            'contact_id' => $contact->id,
            'initial_date' => '2017-01-01',
            'title' => 'fake text saying nothing',
            'frequency_type' => 'year',
            'frequency_number' => 1,
        ]);
        $reminderOutbox = factory(ReminderOutbox::class)->create([
            'account_id' => $user->account_id,
            'reminder_id' => $reminder->id,
            'user_id' => $user->id,
            'planned_date' => '2017-01-01',
            'nature' => 'reminder',
        ]);

        NotifyUserAboutReminder::dispatch($reminderOutbox);

        // Assert the notification has been sent to the user with the right
        // reminderoutbox id and the right email content
        Notification::assertSentTo(
            $user,
            UserReminded::class,
            function ($notification, $channels) use ($reminderOutbox, $user, $contact) {
                $mailData = $notification->toMail($user)->toArray();
                $this->assertEquals("Reminder for {$contact->name}", $mailData['subject']);
                $this->assertEquals("Hi {$user->first_name}", $mailData['greeting']);
                $this->assertContains("You wanted to be reminded of {$reminderOutbox->reminder->title}", $mailData['introLines']);

                return $notification->reminderOutbox->id === $reminderOutbox->id;
            }
        );
    }

    public function test_it_sends_a_notification_to_a_user()
    {
        Notification::fake();

        Carbon::setTestNow(Carbon::create(2017, 1, 1, 7, 0, 0));

        $account = factory(Account::class)->create([
            'default_time_reminder_is_sent' => '07:00',
        ]);
        $contact = factory(Contact::class)->create(['account_id' => $account->id]);
        $user = factory(User::class)->create(['account_id' => $account->id]);
        $reminder = factory(Reminder::class)->create([
            'account_id' => $account->id,
            'contact_id' => $contact->id,
            'initial_date' => '2017-01-01',
            'title' => 'fake text saying nothing',
            'frequency_type' => 'year',
            'frequency_number' => '1',
        ]);
        $reminderOutbox = factory(ReminderOutbox::class)->create([
            'account_id' => $user->account_id,
            'reminder_id' => $reminder->id,
            'user_id' => $user->id,
            'planned_date' => '2017-01-01',
            'nature' => 'notification',
        ]);

        NotifyUserAboutReminder::dispatch($reminderOutbox);

        // Assert the notification has been sent to the user with the right
        // reminderoutbox id and the right email content
        Notification::assertSentTo(
            $user,
            UserNotified::class,
            function ($notification, $channels) use ($reminderOutbox, $user, $contact) {
                $mailData = $notification->toMail($user)->toArray();
                $this->assertEquals("Reminder for {$contact->name}", $mailData['subject']);
                $this->assertEquals("Hi {$user->first_name}", $mailData['greeting']);
                $this->assertContains('In  days (on Jan 01, 2018), the following event will happen:', $mailData['introLines']);

                return $notification->reminderOutbox->id === $reminderOutbox->id;
            }
        );
    }

    public function test_it_doesnt_notify_a_user_if_he_is_on_the_free_plan()
    {
        Notification::fake();

        Carbon::setTestNow(Carbon::create(2017, 1, 1, 7, 0, 0));
        config(['monica.requires_subscription' => true]);

        $account = factory(Account::class)->create([
            'default_time_reminder_is_sent' => '07:00',
            'has_access_to_paid_version_for_free' => false,
        ]);
        $contact = factory(Contact::class)->create(['account_id' => $account->id]);
        $user = factory(User::class)->create(['account_id' => $account->id]);
        $reminder = factory(Reminder::class)->create([
            'account_id' => $account->id,
            'contact_id' => $contact->id,
            'initial_date' => '2017-01-01',
            'title' => 'fake text saying nothing',
            'frequency_type' => 'year',
            'frequency_number' => 1,
        ]);
        $reminderOutbox = factory(ReminderOutbox::class)->create([
            'account_id' => $user->account_id,
            'reminder_id' => $reminder->id,
            'user_id' => $user->id,
            'planned_date' => '2017-01-01',
        ]);

        NotifyUserAboutReminder::dispatch($reminderOutbox);

        Notification::assertNotSentTo(
            $user,
            UserReminded::class
        );
    }

    public function test_it_marks_the_one_time_reminder_has_inactive_once_it_is_sent()
    {
        Notification::fake();

        Carbon::setTestNow(Carbon::create(2017, 1, 1, 7, 0, 0));

        $account = factory(Account::class)->create([
            'default_time_reminder_is_sent' => '07:00',
        ]);
        $contact = factory(Contact::class)->create(['account_id' => $account->id]);
        $user = factory(User::class)->create(['account_id' => $account->id]);
        $reminder = factory(Reminder::class)->create([
            'account_id' => $account->id,
            'contact_id' => $contact->id,
            'initial_date' => '2017-01-01',
            'title' => 'fake text saying nothing',
            'frequency_type' => 'one_time',
        ]);
        $reminderOutbox = factory(ReminderOutbox::class)->create([
            'account_id' => $user->account_id,
            'reminder_id' => $reminder->id,
            'user_id' => $user->id,
            'planned_date' => '2017-01-01',
        ]);

        NotifyUserAboutReminder::dispatch($reminderOutbox);

        $this->assertDatabaseMissing('reminder_outbox', [
            'account_id' => $user->account->id,
            'id' => $reminderOutbox->id,
        ]);

        $this->assertDatabaseHas('reminders', [
            'account_id' => $user->account->id,
            'id' => $reminder->id,
            'inactive' => true,
        ]);
    }

    public function test_it_reschedule_a_recurring_reminder_once_it_is_sent()
    {
        Notification::fake();

        Carbon::setTestNow(Carbon::create(2017, 1, 1, 7, 0, 0));

        $account = factory(Account::class)->create([
            'default_time_reminder_is_sent' => '07:00',
        ]);
        $contact = factory(Contact::class)->create(['account_id' => $account->id]);
        $user = factory(User::class)->create(['account_id' => $account->id]);
        $reminder = factory(Reminder::class)->create([
            'account_id' => $account->id,
            'contact_id' => $contact->id,
            'initial_date' => '2017-01-01',
            'title' => 'fake text saying nothing',
            'frequency_type' => 'year',
            'frequency_number' => 1,
        ]);
        $reminderOutbox = factory(ReminderOutbox::class)->create([
            'account_id' => $user->account_id,
            'reminder_id' => $reminder->id,
            'user_id' => $user->id,
            'planned_date' => '2017-01-01',
        ]);

        NotifyUserAboutReminder::dispatch($reminderOutbox);

        $this->assertDatabaseMissing('reminder_outbox', [
            'account_id' => $user->account->id,
            'id' => $reminderOutbox->id,
        ]);

        $this->assertDatabaseHas('reminders', [
            'account_id' => $user->account->id,
            'id' => $reminder->id,
            'inactive' => false,
        ]);

        $this->assertDatabaseHas('reminder_outbox', [
            'account_id' => $user->account->id,
            'reminder_id' => $reminder->id,
        ]);
    }

    public function test_it_creates_a_reminder_sent_object()
    {
        Notification::fake();
        Carbon::setTestNow(Carbon::create(2017, 1, 1, 7, 0, 0));

        $account = factory(Account::class)->create([
            'default_time_reminder_is_sent' => '07:00',
        ]);
        $contact = factory(Contact::class)->create(['account_id' => $account->id]);
        $user = factory(User::class)->create(['account_id' => $account->id]);
        $reminder = factory(Reminder::class)->create([
            'account_id' => $account->id,
            'contact_id' => $contact->id,
            'initial_date' => '2017-01-01',
            'title' => 'fake text saying nothing',
            'frequency_type' => 'year',
            'frequency_number' => 1,
        ]);
        $reminderOutbox = factory(ReminderOutbox::class)->create([
            'account_id' => $user->account_id,
            'reminder_id' => $reminder->id,
            'user_id' => $user->id,
            'planned_date' => '2017-01-01',
        ]);

        NotifyUserAboutReminder::dispatch($reminderOutbox);

        $this->assertDatabaseHas('reminder_sent', [
            'account_id' => $user->account_id,
            'reminder_id' => $reminder->id,
            'user_id' => $user->id,
            'planned_date' => '2017-01-01',
            'nature' => 'reminder',
        ]);
    }
}
