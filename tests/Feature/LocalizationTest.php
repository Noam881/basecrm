<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\MagicLoginController;
use App\Models\User;
use App\Notifications\MagicLoginLink;
use App\Support\Localization;
use Awove\Core\Installation;
use Awove\Core\Models\Contact;
use Awove\Core\Models\Setting;
use Awove\Core\Models\Status;
use Awove\Core\Modules;
use Awove\Core\Services\Csv;
use Awove\Core\Services\Leads;
use Awove\Core\StatusDisplay;
use Awove\Core\Tables\Catalog;
use Awove\Executive\ExecutiveModule;
use Awove\Marketing\Labels;
use Awove\Nonprofit\Fundraising\Access;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    private ?string $manifestPath = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();
        $this->artisan('crm:install')->assertSuccessful();
    }

    protected function tearDown(): void
    {
        if ($this->manifestPath !== null) {
            @unlink($this->manifestPath);
        }
        app()->setLocale('en');
        parent::tearDown();
    }

    private function installation(array $overrides): void
    {
        $base = Installation::manifest();
        $this->manifestPath ??= tempnam(sys_get_temp_dir(), 'crm-localization-');
        file_put_contents($this->manifestPath, json_encode(array_replace($base, $overrides), JSON_THROW_ON_ERROR));
        chmod($this->manifestPath, 0600);
        config(['core.manifest' => $this->manifestPath]);
    }

    public function test_base_defaults_remain_english_purple_and_unrecognized_language_or_theme_is_rejected(): void
    {
        $this->assertSame('en', Installation::settings()['locale']);
        $this->assertSame('purple', Installation::settings()['theme']);
        $this->get('/login')->assertOk()->assertSee('lang="en"', false)->assertSee('dir="ltr"', false)->assertSee('data-crm-theme="purple"', false);
        $this->installation(['locale' => 'he', 'theme' => 'arbitrary-css']);
        $this->expectException(\RuntimeException::class);
        Installation::manifest();
    }

    public function test_unsupported_locale_is_rejected(): void
    {
        $this->installation(['locale' => 'arbitrary-locale']);
        $this->expectException(\RuntimeException::class);
        Installation::manifest();
    }

    public function test_hebrew_green_is_deployment_controlled_without_relabelling_currency_or_stored_names(): void
    {
        $this->installation(['locale' => 'he', 'theme' => 'green']);
        Setting::updateOrCreate(['key' => 'locale'], ['value' => 'en']);
        Setting::updateOrCreate(['key' => 'theme'], ['value' => 'purple']);
        $currency = Installation::settings()['currency'];
        $user = User::factory()->create(['name' => 'New', 'active' => true]);
        $this->get('/login')->assertOk()->assertSee('lang="he"', false)->assertSee('dir="rtl"', false)->assertSee('data-crm-theme="green"', false)
            ->assertInertia(fn (Assert $page) => $page->where('brand.locale', 'he')->where('brand.theme', 'green')->where('brand.currency', $currency));
        $this->assertSame('New', $user->fresh()->name);
        $this->assertSame($currency, Installation::settings()['currency']);
        $this->assertSame('he', app()->getLocale());
    }

    public function test_hebrew_sign_in_response_is_generic_and_validation_uses_translated_attribute_names(): void
    {
        $this->installation(['locale' => 'he', 'theme' => 'green']);
        $user = User::factory()->create(['email' => 'noa.levi@localization.example.test', 'active' => true]);
        $this->post('/login', ['email' => $user->email])->assertRedirect('/login')->assertSessionHas('status', 'אם כתובת הדוא״ל שייכת לחשבון פעיל, קישור כניסה יישלח אליה. כדאי לבדוק את תיבת הדואר.');
        $this->post('/login', ['email' => 'unknown@localization.example.test'])->assertRedirect('/login')->assertSessionHas('status', __('If this email belongs to an active account, a sign-in link is on its way. Check your inbox.'));
        Notification::assertSentToTimes($user, MagicLoginLink::class, 1);
        $this->postJson('/login', ['email' => 'invalid-address'])->assertUnprocessable()->assertJsonPath('errors.email.0', 'יש להזין כתובת דוא״ל תקינה בשדה דוא״ל.');
    }

    public function test_queue_locale_refreshes_for_each_job_and_email_keeps_its_original_url(): void
    {
        $this->installation(['locale' => 'he', 'theme' => 'green']);
        app()->setLocale('en');
        Event::dispatch(new JobProcessing('database', Mockery::mock(Job::class)->shouldReceive('payload')->andReturn([])->getMock()));
        $this->assertSame('he', app()->getLocale());
        $url = 'https://alpha.example.test/magic-login/fictional?signature=example';
        $mail = (new MagicLoginLink($url))->toMail(new \stdClass);
        $this->assertSame('כניסה למערכת', $mail->actionText);
        $this->assertSame($url, $mail->actionUrl);
        $this->assertStringContainsString('קישור הכניסה שלך', $mail->subject);
        $html = (string) app(Markdown::class)->render('notifications::email', $mail->data());
        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('lang="he"', $html);
        $this->assertStringContainsString('כל הזכויות שמורות', $html);
        $this->installation(['locale' => 'en', 'theme' => 'purple']);
        Event::dispatch(new JobProcessing('database', Mockery::mock(Job::class)->shouldReceive('payload')->andReturn([])->getMock()));
        $this->assertSame('en', app()->getLocale());
        $this->assertSame('Sign in to CRM', (new MagicLoginLink($url))->toMail(new \stdClass)->actionText);
        $this->assertSame(MagicLoginController::SENT_MESSAGE, __(MagicLoginController::SENT_MESSAGE));
    }

    public function test_module_system_messages_are_localized_without_translating_replacements(): void
    {
        $this->installation(['locale' => 'he', 'theme' => 'green']);
        Localization::apply();
        $operationsCatalog = is_file(base_path('vendor/awove/operations/resources/lang/backend/he.json'));
        $this->assertSame($operationsCatalog ? 'ההזמנה השתנתה. יש לטעון מחדש לפני השמירה.' : 'This order changed. Reload before saving.', __('This order changed. Reload before saving.'));
        $marketingCatalog = is_file(base_path('vendor/awove/marketing/resources/lang/backend/he.json'));
        $this->assertSame($marketingCatalog ? 'מועד תוכן: New' : 'Content due: New', __('Content due: :title', ['title' => 'New']));
        $this->assertSame('עמודות חובה חסרות: original_id, created_at.', __('Missing required columns: :columns.', ['columns' => 'original_id, created_at']));
    }

    public function test_default_labels_translate_but_custom_labels_and_stored_sales_data_are_preserved(): void
    {
        $this->installation(['locale' => 'he', 'theme' => 'green', 'executive_task_statuses' => ['not-started' => 'New'], 'marketing_campaigns_statuses' => ['planned' => 'Save']]);
        Localization::apply();
        if (class_exists(ExecutiveModule::class)) {
            $this->assertSame('New', ExecutiveModule::taskStatuses()['not-started']);
            $this->assertSame('בביצוע', ExecutiveModule::taskStatuses()['in-progress']);
        } else {
            $this->assertSame('Open management tasks', __('Open management tasks'));
        }
        if (class_exists(Labels::class)) {
            $this->assertSame('Save', Labels::statuses('campaigns')['planned']);
        } else {
            $this->assertSame('Content due: New', __('Content due: :title', ['title' => 'New']));
        }
        $status = Status::findOrFail('new');
        $this->assertSame('חדש', StatusDisplay::copy($status)->name);
        $this->assertSame('New', $status->fresh()->name);
        $status->update(['name' => 'Save']);
        $this->assertSame('Save', StatusDisplay::copy($status)->name);
        $user = User::factory()->create(['name' => 'New', 'active' => true]);
        $user->assignRole('Employee');
        $contact = Contact::create(['name' => 'Save', 'created_by' => $user->id, 'search_key' => 'save']);
        $lead = app(Leads::class)->create($user, ['contact_id' => $contact->id, 'status_id' => 'new', 'owner_id' => $user->id, 'source' => 'Direct']);
        $this->actingAs($user)->get('/leads/'.$lead->id)->assertOk()->assertInertia(fn (Assert $page) => $page->where('lead.status.name', 'Save')->where('lead.contact.name', 'Save')->where('lead.status_id', 'new'));
        $options = app(Catalog::class)->columns('leads', $user)['status']->options;
        $this->assertContains(['value' => 'Save', 'label' => 'Save'], $options);
        $this->assertSame('New', $user->fresh()->name);
        $this->assertSame('Save', $contact->fresh()->name);
    }

    public function test_report_headers_translate_without_changing_machine_templates_or_row_values(): void
    {
        $this->installation(['locale' => 'he', 'theme' => 'green']);
        Localization::apply();
        $csv = app(Csv::class);
        $report = $csv->download('report.csv', ['name', 'owner_id'], [['New', '123']])->getCallback();
        ob_start();
        $report();
        $contents = ob_get_clean();
        $this->assertStringContainsString('שם', $contents);
        $this->assertStringContainsString('מזהה אחראי', $contents);
        $this->assertStringContainsString('New,123', $contents);
        $template = $csv->download('template.csv', ['name', 'owner_id'], [], localizeHeaders: false)->getCallback();
        ob_start();
        $template();
        $this->assertSame("name,owner_id\r\n", ob_get_clean());
    }

    public function test_static_table_option_captions_localize_while_filter_values_and_custom_options_remain_original(): void
    {
        $this->installation(['locale' => 'he', 'theme' => 'green', 'custom_fields' => ['context' => ['label' => 'Save', 'type' => 'select', 'options' => ['Save', 'New']]]]);
        Localization::apply();
        $user = User::factory()->create(['active' => true]);
        $user->assignRole('Employee');
        $columns = app(Catalog::class)->columns('leads', $user);
        $this->assertSame(['active', 'won', 'lost'], array_column($columns['outcome']->options, 'value'));
        $this->assertSame(['פעיל', 'נסגרה בהצלחה', 'לא נסגרה'], array_column($columns['outcome']->options, 'label'));
        $methodOptions = $columns['contact_preferred_contact_method']->options;
        $this->assertSame(['email', 'phone', 'sms', 'whatsapp', 'any'], array_column($methodOptions, 'value'));
        $this->assertMatchesRegularExpression('/[\x{0590}-\x{05ff}]/u', $methodOptions[1]['label']);
        $tasks = app(Catalog::class)->columns('tasks', $user);
        $this->assertSame(['open', 'completed', 'cancelled'], array_column($tasks['status']->options, 'value'));
        $this->assertSame(['פתוח', 'הושלם', 'בוטל'], array_column($tasks['status']->options, 'label'));
        $this->assertSame('Save', $columns['custom:context']->metadata()['label']);
        $this->assertSame('שדות מותאמים', $columns['custom:context']->metadata()['group']);
        $this->assertSame([['value' => 'Save', 'label' => 'Save'], ['value' => 'New', 'label' => 'New']], $columns['custom:context']->options);
    }

    public function test_only_known_default_lead_titles_are_projected_and_authored_titles_are_preserved(): void
    {
        $this->installation(['locale' => 'he', 'theme' => 'green', 'lead_label' => 'Leads']);
        Localization::apply();
        $this->assertSame('לידים', Installation::display(null)['lead_label']);
        $this->assertSame('Leads', Installation::settings()['lead_label']);
        $this->installation(['lead_label' => 'Enquiries']);
        $this->assertMatchesRegularExpression('/[\x{0590}-\x{05ff}]/u', Installation::display(null)['lead_label']);
        $this->installation(['lead_label' => 'Save']);
        $this->assertSame('Save', Installation::display(null)['lead_label']);
        $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page->where('brand.lead_label', 'Save'));
    }

    public function test_optional_fundraising_role_ids_are_not_translated_even_when_matching_a_message_key(): void
    {
        $this->installation(['locale' => 'he', 'theme' => 'green']);
        Localization::apply();
        // Give the translator a matching key to prove the role list is data, not captions.
        app('translator')->addLines(['*.donor' => 'תורם'], 'he');
        $this->assertSame('תורם', __('donor'));
        if (! class_exists(Access::class)) {
            $this->assertArrayNotHasKey('nonprofit', app(Modules::class)->installed());

            return;
        }
        $this->installation(['modules' => ['nonprofit'], 'capabilities' => ['sales' => true, 'nonprofit:fundraising' => true]]);
        $user = User::factory()->create(['active' => true]);
        $user->givePermissionTo('nonprofit.fundraising.view.own');
        $this->actingAs($user)->get('/nonprofit/fundraising')->assertOk()->assertInertia(fn (Assert $page) => $page->where('roles', ['donor', 'partner', 'prospect']));
    }
}
