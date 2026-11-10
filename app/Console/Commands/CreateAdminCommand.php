<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\University;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Crea el primer administrador (T-40). No existe un endpoint público para crear staff: los demás
 * moderadores y administradores los crea un administrador desde el panel (T-41).
 */
class CreateAdminCommand extends Command
{
    protected $signature = 'kampus:create-admin
                            {--name= : Nombre del administrador}
                            {--email= : Correo institucional del administrador}';

    protected $description = 'Crea una cuenta de administrador con correo institucional ya verificado';

    public function handle(): int
    {
        $name = trim((string) ($this->option('name') ?? $this->ask('Nombre')));
        $email = Str::lower(trim((string) ($this->option('email') ?? $this->ask('Correo institucional'))));
        $password = (string) $this->secret('Contraseña');
        $confirmation = (string) $this->secret('Confirma la contraseña');

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password, 'password_confirmation' => $confirmation],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'confirmed', Password::defaults()],
            ],
        );

        // El staff usa correo institucional, como los alumnos (RF-22).
        $validator->after(function ($validator) use ($email): void {
            if ($email !== '' && University::forEmail($email) === null) {
                $validator->errors()->add('email', 'El correo debe ser de una institución participante.');
            }
        });

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        // `role` no es asignable en masa; forceFill deja claro que solo este comando crea un administrador.
        $admin = (new User)->forceFill([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => UserRole::Admin,
            'university_id' => null,
            'email_verified_at' => now(),
        ]);
        $admin->save();

        $this->info("Administrador creado: {$admin->email} (id {$admin->id}).");

        return self::SUCCESS;
    }
}
