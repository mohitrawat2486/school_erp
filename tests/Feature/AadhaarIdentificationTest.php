<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Student;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\EmployeeService;
use App\Services\ProvisionSchool;
use App\Services\StudentService;
use App\Support\CurrentSchool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsMarksContext;
use Tests\TestCase;

class AadhaarIdentificationTest extends TestCase
{
    use BuildsMarksContext, RefreshDatabase;

    public static function entities(): array
    {
        return ['employee' => ['employee'], 'student' => ['student']];
    }

    private function workspace(string $kind): array
    {
        $admin = User::factory()->create(['school_id' => null]);
        app(ProvisionSchool::class)->run('AADHAAR', 'Identification School', $admin->email);
        $admin = $admin->fresh();
        app(CurrentSchool::class)->set($admin->school);
        $this->actingAs($admin);
        $model = $kind === 'employee' ? Employee::class : Student::class;
        $service = $kind === 'employee' ? EmployeeService::class : StudentService::class;
        $payload = ['first_name' => 'Alex', 'last_name' => 'Sharma'] + ($kind === 'employee' ? ['employee_number' => 'EMP-001', 'employee_type' => 'TEACHER', 'joining_date' => '2020-04-01'] : ['admission_number' => 'ADM-001', 'admission_date' => '2020-04-01']);

        return compact('admin', 'model', 'service', 'payload') + ['table' => $kind === 'employee' ? 'employees' : 'students', 'kind' => $kind];
    }

    #[DataProvider('entities')]
    public function test_create_stores_encrypted_identification_and_redacts_audits(string $kind): void
    {
        $c = $this->workspace($kind);
        $number = '234567890123';
        $this->post('/'.$c['table'], $c['payload'] + ['aadhaar_number' => $number])->assertRedirect()->assertSessionHasNoErrors();
        $record = $c['model']::firstOrFail();
        $ciphertext = DB::table($c['table'])->value('aadhaar_number');
        $this->assertNotSame($number, $ciphertext);
        $this->assertSame($number, Crypt::decryptString($ciphertext));
        $this->assertSame($number, $record->aadhaar_number);
        $this->assertArrayNotHasKey('aadhaar_number', $record->toArray());
        $audit = DB::table('audit_logs')->where('entity_type', $kind)->where('entity_id', $record->id)->first();
        $this->assertSame('[REDACTED]', json_decode($audit->new_values, true)['aadhaar_number']);
        $this->assertStringNotContainsString($number, $audit->new_values);
        $this->assertStringNotContainsString($ciphertext, $audit->new_values);
    }

    #[DataProvider('entities')]
    public function test_edit_requires_identification_and_allows_valid_updates(string $kind): void
    {
        $c = $this->workspace($kind);
        $record = $c['model']::factory()->create($c['payload'] + ['aadhaar_number' => '234567890123']);
        $url = '/'.$c['table'].'/'.$record->id;
        $this->get($url.'/edit')->assertOk()->assertSee('value="234567890123"', false);
        $this->put($url, $c['payload'])->assertSessionHasErrors('aadhaar_number');
        $this->assertSame('234567890123', $record->fresh()->aadhaar_number);
        $this->put($url, $c['payload'] + ['aadhaar_number' => '345678901234'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('345678901234', $record->fresh()->aadhaar_number);
        $this->put($url, $c['payload'] + ['aadhaar_number' => ''])->assertSessionHasErrors('aadhaar_number');
        $this->assertSame('345678901234', $record->fresh()->aadhaar_number);
        foreach (DB::table('audit_logs')->where('entity_type', $kind)->get() as $audit) {
            $this->assertStringNotContainsString('234567890123', $audit->old_values.$audit->new_values);
            $this->assertStringNotContainsString('345678901234', $audit->old_values.$audit->new_values);
        }
    }

    #[DataProvider('entities')]
    public function test_missing_and_blank_numbers_are_rejected_for_new_and_legacy_records(string $kind): void
    {
        $c = $this->workspace($kind);
        foreach ([[], ['aadhaar_number' => ''], ['aadhaar_number' => null], ['aadhaar_number' => '   ']] as $extra) {
            $this->post('/'.$c['table'], $c['payload'] + $extra)->assertSessionHasErrors('aadhaar_number');
        }
        $this->assertDatabaseCount($c['table'], 0);
        $record = $c['model']::factory()->create($c['payload']);
        $this->get('/'.$c['table'].'/'.$record->id)->assertOk()->assertSee('Aadhaar number')->assertSee('Not recorded');
        $this->put('/'.$c['table'].'/'.$record->id, $c['payload'])->assertSessionHasErrors('aadhaar_number');
        $this->put('/'.$c['table'].'/'.$record->id, $c['payload'] + ['aadhaar_number' => '234567890123'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('234567890123', $record->fresh()->aadhaar_number);
    }

    #[DataProvider('entities')]
    public function test_invalid_values_cannot_change_existing_identification(string $kind): void
    {
        $c = $this->workspace($kind);
        $record = $c['model']::factory()->create($c['payload'] + ['aadhaar_number' => '234567890123']);
        foreach (['12345678901', '1234567890123', '2345 6789 0123', '2345-6789-0123', '23456789012A', '２３４５６７８９０１２３', ['234567890123'], 234567890123] as $invalid) {
            $this->put('/'.$c['table'].'/'.$record->id, $c['payload'] + ['aadhaar_number' => $invalid])->assertSessionHasErrors('aadhaar_number');
            $this->assertSame('234567890123', $record->fresh()->aadhaar_number);
        }
    }

    #[DataProvider('entities')]
    public function test_profiles_mask_identification_and_directories_omit_it(string $kind): void
    {
        $c = $this->workspace($kind);
        $record = $c['model']::factory()->create($c['payload'] + ['aadhaar_number' => '234567890123']);
        $this->get('/'.$c['table'].'/'.$record->id)->assertOk()->assertSee('XXXX XXXX 0123')->assertDontSee('234567890123');
        $this->get('/'.$c['table'])->assertOk()->assertDontSee('234567890123');
        $reader = $this->user($c, [$kind.'.view']);
        $this->actingAs($reader)->get('/'.$c['table'].'/'.$record->id)->assertOk()->assertSee('XXXX XXXX 0123')->assertDontSee('234567890123');
        $this->get('/'.$c['table'].'/'.$record->id.'/edit')->assertForbidden();
        $this->put('/'.$c['table'].'/'.$record->id, $c['payload'] + ['aadhaar_number' => '345678901234'])->assertForbidden();
    }

    #[DataProvider('entities')]
    public function test_forms_restore_identification_after_validation_error(string $kind): void
    {
        $c = $this->workspace($kind);
        $form = $this->get('/'.$c['table'].'/create')->assertOk()->assertSee('name="aadhaar_number"', false)->assertSee('inputmode="numeric"', false)->assertSee('Aadhaar number')->assertDontSee('Aadhaar number (optional)');
        $dom = new \DOMDocument;
        @$dom->loadHTML($form->getContent());
        $xpath = new \DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//input[@name="aadhaar_number"][@required]')->length);
        $this->assertSame(0, $xpath->query('//details//input[@name="aadhaar_number"]')->length);
        $this->from('/'.$c['table'].'/create')->post('/'.$c['table'], array_replace($c['payload'], ['first_name' => '', 'aadhaar_number' => '234567890123']))->assertRedirect('/'.$c['table'].'/create')->assertSessionHasErrors('first_name');
        $this->get('/'.$c['table'].'/create')->assertOk()->assertSee('value="234567890123"', false);
    }

    #[DataProvider('entities')]
    public function test_unchanged_encrypted_value_does_not_add_an_audit(string $kind): void
    {
        $c = $this->workspace($kind);
        $record = $c['model']::factory()->create($c['payload'] + ['aadhaar_number' => '234567890123']);
        $count = DB::table('audit_logs')->count();
        $this->put('/'.$c['table'].'/'.$record->id, $c['payload'] + ['aadhaar_number' => '234567890123'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('audit_logs', $count);
    }

    #[DataProvider('entities')]
    public function test_service_rejects_invalid_identification_and_foreign_updates(string $kind): void
    {
        $c = $this->workspace($kind);
        foreach ([[], ['aadhaar_number' => null], ['aadhaar_number' => ''], ['aadhaar_number' => 'invalid']] as $extra) {
            try {
                app($c['service'])->save($c['admin'], null, $c['payload'] + $extra);
                $this->fail('Expected missing or invalid Aadhaar to be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('aadhaar_number', $exception->errors());
            }
        }
        $this->assertDatabaseCount($c['table'], 0);
        $other = User::factory()->create();
        app(CurrentSchool::class)->set($other->school);
        $foreign = $c['model']::factory()->create(['aadhaar_number' => '345678901234']);
        app(CurrentSchool::class)->set($c['admin']->school);
        $this->get('/'.$c['table'].'/'.$foreign->id)->assertNotFound()->assertDontSee('345678901234');
        $this->put('/'.$c['table'].'/'.$foreign->id, $c['payload'] + ['aadhaar_number' => '234567890123'])->assertNotFound();
    }

    #[DataProvider('entities')]
    public function test_audit_failure_rolls_back_identification_changes(string $kind): void
    {
        $c = $this->workspace($kind);
        $record = $c['model']::factory()->create($c['payload'] + ['aadhaar_number' => '234567890123']);
        $original = DB::table($c['table'])->where('id', $record->id)->value('aadhaar_number');
        $this->mock(AuditLogger::class)->shouldReceive('record')->andThrow(new \RuntimeException('Audit failed'));
        try {
            app($c['service'])->save($c['admin'], $record, $c['payload'] + ['aadhaar_number' => '345678901234']);
            $this->fail('Expected audit failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit failed', $exception->getMessage());
        }
        $this->assertSame($original, DB::table($c['table'])->where('id', $record->id)->value('aadhaar_number'));
        $this->assertSame('234567890123', $record->fresh()->aadhaar_number);
    }
}
