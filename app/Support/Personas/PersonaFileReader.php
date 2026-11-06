<?php

namespace App\Support\Personas;

use App\Data\Personas\PersonaData;
use App\Enums\PersonaEmojiFrequency;
use App\Enums\PersonaEngagement;
use App\Exceptions\Personas\InvalidPersonaFileException;
use Illuminate\Support\Facades\Validator;
use JsonException;

/**
 * Reads a file of Personas to import (`studio:personas:import`): a JSON list
 * of objects, each with exactly PersonaData's fields — none missing, none
 * besides — each value of its type and held to the rules a Persona made in
 * the editor is (PersonaValidationRules::importRules()), as it would be
 * stored, and every username once.
 *
 * The whole file is read before anything is said of it, so every problem in
 * it is listed at once, and a file with any is refused whole: an import
 * never starts from a file it could finish only in part.
 *
 * Nothing of any one project is here: which Personas a project has is its
 * file's to say.
 */
final class PersonaFileReader
{
    /** The fields of every entry, as PersonaData names them. */
    public const FIELDS = [
        'displayName',
        'username',
        'bio',
        'shortDescription',
        'character',
        'speechStyle',
        'topics',
        'neverWritesAbout',
        'emojiFrequency',
        'engagement',
        'maxRepliesPerDay',
    ];

    /** The fields an entry may leave empty, with null. */
    private const NULLABLE = ['bio', 'shortDescription', 'character', 'speechStyle', 'topics', 'neverWritesAbout'];

    /** The largest file read, in bytes: room for far more Personas than a project has. */
    public const MOST_BYTES = 4 * 1024 * 1024;

    public function __construct(private readonly PersonaValidationRules $rules) {}

    /**
     * @return list<PersonaData> in the file's order, as each would be stored
     *
     * @throws InvalidPersonaFileException with every problem found
     */
    public function read(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw InvalidPersonaFileException::because(["{$path} is not a file that can be read."]);
        }

        if (filesize($path) > self::MOST_BYTES) {
            throw InvalidPersonaFileException::because(["{$path} is larger than ".(self::MOST_BYTES / 1024 / 1024).' MB.']);
        }

        try {
            $entries = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw InvalidPersonaFileException::because(["{$path} is not valid JSON: {$exception->getMessage()}."]);
        }

        if (! is_array($entries) || ! array_is_list($entries) || $entries === []) {
            throw InvalidPersonaFileException::because(['The file must hold a JSON list of Personas, at least one.']);
        }

        $problems = [];
        $personas = [];
        $entryOf = [];

        foreach ($entries as $index => $entry) {
            $label = 'Entry '.($index + 1);
            $persona = $this->entry($entry, $label, $problems);

            if ($persona === null) {
                continue;
            }

            $username = (string) $persona->username;

            if (isset($entryOf[$username])) {
                $problems[] = "{$label} ({$username}): the username is entry {$entryOf[$username]}’s too.";

                continue;
            }

            $entryOf[$username] = $index + 1;
            $personas[] = $persona;
        }

        if ($problems !== []) {
            throw InvalidPersonaFileException::because($problems);
        }

        return $personas;
    }

    /**
     * One entry as it would be stored, or none — with what is wrong with it
     * added to the problems.
     *
     * @param  list<string>  $problems
     */
    private function entry(mixed $entry, string $label, array &$problems): ?PersonaData
    {
        if (! is_array($entry) || array_is_list($entry)) {
            $problems[] = "{$label}: must be an object with a Persona’s fields.";

            return null;
        }

        if (is_string($entry['username'] ?? null) && trim($entry['username']) !== '') {
            $label .= ' ('.trim($entry['username']).')';
        }

        $found = count($problems);
        $missing = array_values(array_diff(self::FIELDS, array_keys($entry)));
        $unknown = array_values(array_diff(array_keys($entry), self::FIELDS));

        if ($missing !== []) {
            $problems[] = "{$label}: missing ".implode(', ', $missing).'.';
        }

        if ($unknown !== []) {
            $problems[] = "{$label}: no such field as ".implode(', ', array_map(strval(...), $unknown)).'.';
        }

        foreach (array_intersect(self::FIELDS, array_keys($entry)) as $field) {
            $value = $entry[$field];
            $fits = match (true) {
                $field === 'maxRepliesPerDay' => is_int($value),
                in_array($field, self::NULLABLE, true) => $value === null || is_string($value),
                default => is_string($value),
            };

            if (! $fits) {
                $problems[] = "{$label}: {$field} must be ".match (true) {
                    $field === 'maxRepliesPerDay' => 'a whole number.',
                    in_array($field, self::NULLABLE, true) => 'text, or null.',
                    default => 'text.',
                };
            }
        }

        if (count($problems) > $found) {
            return null;
        }

        // As PersonaData::normalized() stores them, so the rules hold the
        // values that would be written.
        $values = [
            'displayName' => PersonaData::normalizedName($entry['displayName']),
            'username' => PersonaData::normalizedText($entry['username']),
            'bio' => PersonaData::normalizedText($entry['bio']),
            'shortDescription' => PersonaData::normalizedText($entry['shortDescription']),
            'character' => PersonaData::normalizedText($entry['character']),
            'speechStyle' => PersonaData::normalizedText($entry['speechStyle']),
            'topics' => PersonaData::normalizedText($entry['topics']),
            'neverWritesAbout' => PersonaData::normalizedText($entry['neverWritesAbout']),
            'emojiFrequency' => $entry['emojiFrequency'],
            'engagement' => $entry['engagement'],
            'maxRepliesPerDay' => $entry['maxRepliesPerDay'],
        ];

        $validator = Validator::make($values, $this->rules->importRules(), $this->rules->messages());

        if ($validator->fails()) {
            foreach ($validator->errors()->messages() as $field => $messages) {
                $problems[] = "{$label}: {$field} — {$messages[0]}";
            }

            return null;
        }

        return new PersonaData(
            displayName: $values['displayName'],
            username: $values['username'],
            bio: $values['bio'],
            shortDescription: $values['shortDescription'],
            character: $values['character'],
            speechStyle: $values['speechStyle'],
            topics: $values['topics'],
            neverWritesAbout: $values['neverWritesAbout'],
            emojiFrequency: PersonaEmojiFrequency::from($values['emojiFrequency']),
            engagement: PersonaEngagement::from($values['engagement']),
            maxRepliesPerDay: $values['maxRepliesPerDay'],
        );
    }
}
