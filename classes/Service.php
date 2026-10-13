<?php
require_once __DIR__ . '/Database.php';

// Represents the services the salon offers (cut, colour, treatment...).
class Service
{
    // Returns every service as an array, sorted by name.
    public static function getAll(): array
    {
        $db   = Database::getConnection();
        $stmt = $db->query(
            'SELECT service_id, name, description, duration_minutes, price
             FROM Service
             ORDER BY name'
        );
        return $stmt->fetchAll();
    }

    // Returns one service by its id, or null if it does not exist.
    public static function find(int $serviceId): ?array
    {
        $db   = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT service_id, name, description, duration_minutes, price
             FROM Service
             WHERE service_id = :id'
        );
        $stmt->execute([':id' => $serviceId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    // Every service, with how many bookings use it (for the administrator's list).
    public static function getAllWithUsage(): array
    {
        $stmt = Database::getConnection()->query(
            'SELECT s.service_id, s.name, s.description, s.duration_minutes, s.price,
                    (SELECT COUNT(*) FROM Booking b WHERE b.service_id = s.service_id) AS bookings
             FROM Service s
             ORDER BY s.name'
        );
        return $stmt->fetchAll();
    }

    // Checks what the administrator typed into the add / edit form.
    // Returns [clean values, errors]. Nothing should be saved if there are errors.
    // $ignoreId is the service being edited (so it does not clash with itself).
    public static function validate(array $input, ?int $ignoreId = null): array
    {
        $errors       = [];
        $name         = trim((string) ($input['name'] ?? ''));
        $description  = trim((string) ($input['description'] ?? ''));
        $durationText = trim((string) ($input['duration_minutes'] ?? ''));
        $priceText    = trim((string) ($input['price'] ?? ''));

        if ($name === '' || mb_strlen($name) > 100) {
            $errors['name'] = 'Please enter a name (up to 100 characters).';
        } elseif (self::nameInUse($name, $ignoreId)) {
            $errors['name'] = 'There is already a service with that name.';
        }

        if (mb_strlen($description) > 1000) {
            $errors['description'] = 'The description can be up to 1000 characters.';
        }

        $duration = filter_var($durationText, FILTER_VALIDATE_INT);
        if ($duration === false || $duration < 5 || $duration > 480) {
            $errors['duration_minutes'] = 'The duration must be a whole number of minutes, from 5 to 480.';
        }

        $price = null;
        if (!preg_match('/^\d{1,6}([.,]\d{1,2})?$/', $priceText)) {
            $errors['price'] = 'The price must be a number like 150 or 150.50 (at most 6 digits and 2 decimals).';
        } else {
            $price = (float) str_replace(',', '.', $priceText);
            if ($price <= 0) {
                $errors['price'] = 'The price must be more than zero.';
            }
        }

        $clean = [
            'name'             => $name,
            'description'      => ($description === '') ? null : $description,
            'duration_minutes' => ($duration === false) ? 0 : $duration,
            'price'            => $price,
        ];
        return [$clean, $errors];
    }

    // Adds a service. Returns its new id. Use validate() first.
    public static function create(array $clean): int
    {
        $db = Database::getConnection();
        $db->prepare(
            'INSERT INTO Service (name, description, duration_minutes, price)
             VALUES (:name, :description, :duration, :price)'
        )->execute([
            ':name'        => $clean['name'],
            ':description' => $clean['description'],
            ':duration'    => $clean['duration_minutes'],
            ':price'       => $clean['price'],
        ]);
        return (int) $db->lastInsertId();
    }

    // Changes a service. Existing bookings keep the end time they were booked
    // with; the new duration applies to NEW bookings. Use validate() first.
    public static function update(int $serviceId, array $clean): void
    {
        Database::getConnection()->prepare(
            'UPDATE Service
             SET name = :name, description = :description, duration_minutes = :duration, price = :price
             WHERE service_id = :id'
        )->execute([
            ':name'        => $clean['name'],
            ':description' => $clean['description'],
            ':duration'    => $clean['duration_minutes'],
            ':price'       => $clean['price'],
            ':id'          => $serviceId,
        ]);
    }

    // Deletes a service that has never been booked. A service that appears in
    // any booking (even an old one) cannot be deleted, because the booking
    // history would lose its meaning.
    // Returns '' when it worked, otherwise a message explaining why not.
    public static function delete(int $serviceId): string
    {
        $db = Database::getConnection();

        if (self::find($serviceId) === null) {
            return 'We could not find that service.';
        }

        $used = $db->prepare('SELECT COUNT(*) FROM Booking WHERE service_id = :id');
        $used->execute([':id' => $serviceId]);
        if ((int) $used->fetchColumn() > 0) {
            return 'That service appears in existing bookings, so it cannot be deleted. You can edit it instead.';
        }

        $db->prepare('DELETE FROM Service WHERE service_id = :id')->execute([':id' => $serviceId]);
        return '';
    }

    // Is there already a service with this name (ignoring capital letters)?
    private static function nameInUse(string $name, ?int $ignoreId): bool
    {
        $db = Database::getConnection();

        if ($ignoreId === null) {
            $stmt = $db->prepare('SELECT COUNT(*) FROM Service WHERE name = :name');
            $stmt->execute([':name' => $name]);
        } else {
            $stmt = $db->prepare('SELECT COUNT(*) FROM Service WHERE name = :name AND service_id <> :id');
            $stmt->execute([':name' => $name, ':id' => $ignoreId]);
        }
        return (int) $stmt->fetchColumn() > 0;
    }
}
