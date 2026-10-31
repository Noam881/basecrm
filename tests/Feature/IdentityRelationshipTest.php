<?php

use App\Models\User;
use Awove\Core\Access;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Core\Models\IdentityRelationship;
use Awove\Core\Models\IdentityRelationshipHistory;
use Awove\Core\Services\Identities;
use Awove\Core\Services\IdentityRelationships;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
    $this->artisan('crm:install')->assertSuccessful();
});

function classificationStaff(string $name = 'Noa Cohen'): User
{
    $user = User::factory()->create(['name' => $name, 'active' => true]);
    $user->assignRole('Employee');
    $user->givePermissionTo(['identities.classifications.view', 'identities.classifications.manage']);

    return $user;
}

it('classifies the same shared identity without copying phone or email into another directory', function () {
    $actor = classificationStaff();
    $company = app(Identities::class)->create($actor, 'companies', ['name' => 'Levi Studio', 'phone' => '+1 202-555-0191', 'email' => 'levi@example.test']);
    $person = app(Identities::class)->create($actor, 'contacts', ['name' => 'Tamar Levi', 'company_id' => $company->id]);
    $service = app(IdentityRelationships::class);
    $supplier = $service->create($actor, ['company_id' => $company->id, 'role' => 'supplier', 'main_contact_id' => $person->id, 'notes' => 'Fictional relationship notes.']);
    $actor->givePermissionTo('identities.classifications.assign');
    $customer = $service->create($actor, ['company_id' => $company->id, 'role' => 'customer', 'responsible_id' => null]);
    expect($supplier->version)->toBe(1)->and($supplier->responsible_id)->toBe($actor->id)
        ->and($customer->responsible_id)->toBeNull()->and(Company::count())->toBe(1)->and(Contact::count())->toBe(1);
    expect($service->describe($actor, $supplier))->toMatchArray(['name' => 'Levi Studio', 'email' => 'levi@example.test', 'phone' => '+1 202-555-0191', 'type' => 'company', 'main_contact_id' => $person->id]);
    app(Identities::class)->update($actor, 'companies', $company->id, ['phone' => '+1 202-555-0192']);
    expect($service->describe($actor, $supplier)['phone'])->toBe('+1 202-555-0192');
    expect($service->query($actor, 'supplier')->pluck('id')->all())->toBe([$supplier->id]);
    expect(fn () => $service->create($actor, ['company_id' => $company->id, 'role' => 'supplier']))->toThrow(ValidationException::class);
    $entry = IdentityRelationshipHistory::where('relationship_id', $supplier->id)->sole();
    expect($entry->event)->toBe('classification.created')->and($entry->actor_id)->toBe($actor->id)
        ->and($entry->toJson())->not->toContain('Fictional relationship notes.', 'levi@example.test', '+1 202');
});

it('keeps classification visibility inside the independently authorized identity scope', function () {
    $owner = classificationStaff();
    $other = classificationStaff('Uri Ben David');
    $person = app(Identities::class)->create($owner, 'contacts', ['name' => 'Yael Shalev', 'email' => 'yael@example.test']);
    $service = app(IdentityRelationships::class);
    $relationship = $service->create($owner, ['contact_id' => $person->id, 'role' => 'customer']);
    expect($service->query($other)->count())->toBe(0);
    expect(fn () => $service->describe($other, $relationship))->toThrow(ModelNotFoundException::class);
    expect(fn () => $service->update($other, $relationship->id, ['version' => 1, 'notes' => 'No access']))->toThrow(ModelNotFoundException::class);
    expect(fn () => $service->create($other, ['contact_id' => $person->id, 'role' => 'supplier']))->toThrow(ModelNotFoundException::class);
    $other->givePermissionTo('identities.view.all');
    expect($service->query($other)->count())->toBe(1)->and(Gate::forUser($other)->allows('view', $person))->toBeTrue()
        ->and(Gate::forUser($other)->allows('update', $person))->toBeFalse();
    $other->revokePermissionTo(['identities.classifications.view', 'identities.classifications.manage']);
    expect(Access::identities($other, Contact::class)->count())->toBe(1)->and($service->query($other)->count())->toBe(0);
    expect(fn () => $service->history($other, $relationship))->toThrow(HttpException::class);
    $other->givePermissionTo('identities.classifications.view');
    $other->active = false;
    expect($service->query($other)->count())->toBe(0);
});

it('does not grant classified identity access by assigning responsibility', function () {
    $owner = classificationStaff();
    $other = classificationStaff('Eitan Erez');
    $person = app(Identities::class)->create($owner, 'contacts', ['name' => 'Shira Cohen']);
    $service = app(IdentityRelationships::class);
    expect(fn () => $service->create($owner, ['contact_id' => $person->id, 'role' => 'supplier', 'responsible_id' => $other->id]))->toThrow(HttpException::class);
    $owner->givePermissionTo('identities.classifications.assign');
    $record = $service->create($owner, ['contact_id' => $person->id, 'role' => 'supplier', 'responsible_id' => $other->id]);
    expect($service->query($other)->count())->toBe(0)->and(Access::identities($other, Contact::class)->count())->toBe(0);
    $other->forceFill(['active' => false])->save();
    $record = $service->update($owner, $record->id, ['version' => 1, 'notes' => 'Historical owner retained.']);
    expect($record->responsible_id)->toBe($other->id);
    $unassigned = $service->create($owner, ['contact_id' => $person->id, 'role' => 'customer', 'responsible_id' => null]);
    expect(fn () => $service->update($owner, $unassigned->id, ['version' => 1, 'responsible_id' => $other->id]))->toThrow(ValidationException::class);
});

it('validates company main contacts and redacts an inaccessible retained contact', function () {
    $actor = classificationStaff();
    $other = classificationStaff('Noam Erez');
    $company = app(Identities::class)->create($actor, 'companies', ['name' => 'Cohen Studio']);
    $person = app(Identities::class)->create($actor, 'contacts', ['name' => 'Miriam Cohen']);
    $service = app(IdentityRelationships::class);
    expect(fn () => $service->create($actor, ['company_id' => $company->id, 'role' => 'customer', 'main_contact_id' => $person->id]))->toThrow(ValidationException::class);
    expect(fn () => $service->create($actor, ['contact_id' => $person->id, 'role' => 'customer', 'main_contact_id' => $person->id]))->toThrow(ValidationException::class);
    app(Identities::class)->update($actor, 'contacts', $person->id, ['company_id' => $company->id]);
    $record = $service->create($actor, ['company_id' => $company->id, 'role' => 'customer', 'main_contact_id' => $person->id]);
    // Synthetic ownership change simulates a historical main contact becoming inaccessible.
    $person->update(['created_by' => $other->id]);
    $view = $service->describe($actor, $record);
    expect($view['main_contact_id'])->toBeNull()->and($view['main_contact'])->toBeNull()->and($view['has_hidden_main_contact'])->toBeTrue();
    $service->update($actor, $record->id, ['version' => 1, 'notes' => 'An unrelated edit.', 'main_contact_id' => null]);
    expect($record->fresh()->main_contact_id)->toBe($person->id);
});

it('retains archive and immutable history while rejecting stale or identity-swapping updates', function () {
    $actor = classificationStaff();
    $person = app(Identities::class)->create($actor, 'contacts', ['name' => 'Daniel Shalev']);
    $service = app(IdentityRelationships::class);
    $record = $service->create($actor, ['contact_id' => $person->id, 'role' => 'partner']);
    $service->update($actor, $record->id, ['version' => 1, 'notes' => 'Current notes.']);
    expect(fn () => $service->update($actor, $record->id, ['version' => 1, 'notes' => 'Stale notes.']))->toThrow(ValidationException::class);
    expect(fn () => $service->update($actor, $record->id, ['version' => 2, 'role' => 'supplier']))->toThrow(ValidationException::class);
    $record = $service->archive($actor, $record->id, ['version' => 2, 'archived' => true]);
    expect($service->query($actor)->count())->toBe(0)->and($service->query($actor, null, true)->count())->toBe(1)
        ->and($record->version)->toBe(3)->and($record->notes)->toBe('Current notes.');
    expect(fn () => $service->create($actor, ['contact_id' => $person->id, 'role' => 'partner']))->toThrow(ValidationException::class);
    $record = $service->archive($actor, $record->id, ['version' => 3, 'archived' => false]);
    expect($service->history($actor, $record))->toHaveCount(4)->and($service->query($actor)->count())->toBe(1);
    $entry = IdentityRelationshipHistory::firstOrFail();
    expect(fn () => $entry->update(['event' => 'Forged']))->toThrow(LogicException::class);
    expect(fn () => $entry->delete())->toThrow(LogicException::class);
    expect($record->fresh()->role)->toBe('partner');
});

it('requires one identity and a known role in both service validation and PostgreSQL constraints', function () {
    $actor = classificationStaff();
    $person = app(Identities::class)->create($actor, 'contacts', ['name' => 'Ari Levi']);
    $company = app(Identities::class)->create($actor, 'companies', ['name' => 'Levi Office']);
    $service = app(IdentityRelationships::class);
    foreach ([[], ['contact_id' => $person->id, 'company_id' => $company->id], ['contact_id' => $person->id, 'role' => 'employee']] as $input) {
        expect(fn () => $service->create($actor, [...$input, 'role' => $input['role'] ?? 'customer']))->toThrow(ValidationException::class);
    }
    foreach ([['contact_id' => null, 'company_id' => null], ['contact_id' => $person->id, 'company_id' => $company->id]] as $ids) {
        expect(fn () => DB::transaction(fn () => IdentityRelationship::create([...$ids, 'role' => 'customer', 'created_by' => $actor->id])))->toThrow(QueryException::class);
    }
    expect(IdentityRelationship::count())->toBe(0);
});

it('does not auto grant the new shared classifications or broad identity read permission', function () {
    $admin = User::factory()->create(['active' => true]);
    $admin->assignRole('Administrator');
    expect($admin->can('identities.view.all'))->toBeFalse()->and($admin->can('identities.classifications.view'))->toBeFalse()
        ->and($admin->can('identities.classifications.manage'))->toBeFalse()->and($admin->can('identities.manage'))->toBeTrue();
    $this->artisan('crm:install')->assertSuccessful();
    expect($admin->fresh()->can('identities.classifications.manage'))->toBeFalse();
});

it('rechecks persisted active and permission state for stale caller objects', function () {
    $actor = classificationStaff();
    $person = app(Identities::class)->create($actor, 'contacts', ['name' => 'Aviv Shalev']);
    $service = app(IdentityRelationships::class);
    $record = $service->create($actor, ['contact_id' => $person->id, 'role' => 'customer']);
    $actor->load('permissions');
    $actor->fresh()->revokePermissionTo(['identities.classifications.view', 'identities.classifications.manage']);
    expect($service->query($actor)->count())->toBe(0);
    expect(fn () => $service->update($actor, $record->id, ['version' => 1, 'notes' => 'Access revoked.']))->toThrow(HttpException::class);
    $actor->fresh()->givePermissionTo('identities.classifications.manage');
    $actor->fresh()->forceFill(['active' => false])->save();
    expect($service->query($actor)->count())->toBe(0);
    expect(fn () => $service->create($actor, ['contact_id' => $person->id, 'role' => 'supplier']))->toThrow(HttpException::class);
    expect($record->fresh()->version)->toBe(1);
});

it('requires assignment permission for clearing a responsible person through direct service calls', function () {
    $actor = classificationStaff();
    $person = app(Identities::class)->create($actor, 'contacts', ['name' => 'Lior Erez']);
    $service = app(IdentityRelationships::class);
    $record = $service->create($actor, ['contact_id' => $person->id, 'role' => 'customer']);
    expect(fn () => $service->update($actor, $record->id, ['version' => 1, 'responsible_id' => null]))->toThrow(HttpException::class);
    expect(fn () => $service->create($actor, ['contact_id' => $person->id, 'role' => 'supplier', 'responsible_id' => null]))->toThrow(HttpException::class);
    $actor->givePermissionTo('identities.classifications.assign');
    $record = $service->update($actor, $record->id, ['version' => 1, 'responsible_id' => null]);
    $actor->revokePermissionTo('identities.classifications.assign');
    $service->update($actor, $record->id, ['version' => 2, 'notes' => 'Unchanged unassigned reference retained.']);
    expect($record->fresh()->responsible_id)->toBeNull()->and($record->fresh()->version)->toBe(3);
});
