<?php

declare(strict_types=1);

namespace App\Services\Memory;

use App\Enums\EntityMemorySubjectType;
use App\Models\Chat;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use App\Support\TenantCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class EntityMemorySubjectResolver
{
    /**
     * @return array{title: string, subtitle: string|null}
     */
    public function describe(EntityMemorySubjectType $type, int $subjectId): array
    {
        return match ($type) {
            EntityMemorySubjectType::Tenant => $this->describeTenant($subjectId),
            EntityMemorySubjectType::Contact => $this->describeContact($subjectId),
            EntityMemorySubjectType::Employee => $this->describeEmployee($subjectId),
            EntityMemorySubjectType::ClientCompany => $this->describeClientCompany($subjectId),
        };
    }

    public function assertExists(EntityMemorySubjectType $type, int $subjectId): void
    {
        $this->describe($type, $subjectId);
    }

    /**
     * @return array{title: string, subtitle: string|null}
     */
    private function describeTenant(int $subjectId): array
    {
        if ($subjectId !== TenantCompany::id()) {
            throw new ModelNotFoundException('Tenant company not found.');
        }

        $company = Company::query()->findOrFail($subjectId);

        return [
            'title' => $company->name,
            'subtitle' => 'Наша компания',
        ];
    }

    /**
     * @return array{title: string, subtitle: string|null}
     */
    private function describeContact(int $subjectId): array
    {
        $contact = Contact::query()->findOrFail($subjectId);
        $name = trim((string) ($contact->name ?: $contact->push_name ?: $contact->phone_number ?: 'Клиент'));

        return [
            'title' => $name,
            'subtitle' => $contact->phone_number,
        ];
    }

    /**
     * @return array{title: string, subtitle: string|null}
     */
    private function describeEmployee(int $subjectId): array
    {
        $user = User::query()->findOrFail($subjectId);

        return [
            'title' => trim((string) ($user->name ?: 'Сотрудник')),
            'subtitle' => $user->email,
        ];
    }

    /**
     * @return array{title: string, subtitle: string|null}
     */
    private function describeClientCompany(int $subjectId): array
    {
        $company = Company::query()->findOrFail($subjectId);

        return [
            'title' => $company->name,
            'subtitle' => 'Компания клиента',
        ];
    }

    public function userCanManage(User $user, EntityMemorySubjectType $type, int $subjectId): bool
    {
        try {
            $this->assertExists($type, $subjectId);
        } catch (ModelNotFoundException) {
            return false;
        }

        if ($user->hasRole('administrator') || $user->hasRole('manager')) {
            return true;
        }

        return match ($type) {
            EntityMemorySubjectType::Tenant => false,
            EntityMemorySubjectType::Employee => (int) $user->id === $subjectId,
            EntityMemorySubjectType::Contact => $this->employeeCanAccessContact($user, $subjectId),
            EntityMemorySubjectType::ClientCompany => $this->employeeCanAccessClientCompany($user, $subjectId),
        };
    }

    private function employeeCanAccessContact(User $user, int $contactId): bool
    {
        return Chat::query()
            ->where('contact_id', $contactId)
            ->where('is_group', false)
            ->whereHas('assignments', fn ($q) => $q->where('user_id', $user->id))
            ->exists();
    }

    private function employeeCanAccessClientCompany(User $user, int $companyId): bool
    {
        return Company::query()
            ->whereKey($companyId)
            ->whereHas('contacts.chats.assignments', fn ($q) => $q->where('user_id', $user->id))
            ->exists();
    }

    public function authorizeManage(User $user, EntityMemorySubjectType $type, int $subjectId): void
    {
        if (! $this->userCanManage($user, $type, $subjectId)) {
            throw new AuthorizationException('Недостаточно прав для редактирования memory.md.');
        }
    }
}
