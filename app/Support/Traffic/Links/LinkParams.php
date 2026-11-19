<?php

namespace App\Support\Traffic\Links;

use App\Enums\TrafficLinkParam;

/**
 * The parameters a network's link carries, as a preset or a source lists
 * them: each of a kind the click capture reads, under a name a click can
 * carry — not rg_*, not one the site's own pages read — once, with a token
 * the capture recognises as the network's, and exactly one with the click
 * ID.
 */
final class LinkParams
{
    public const int MOST = 12;

    /**
     * What is wrong, by the parameter's place in the list (-1 for the list).
     *
     * @param  array<int, mixed>  $params
     * @param  list<string>  $reserved
     * @return list<array{index: int, field: string, message: string}>
     */
    public static function violations(array $params, array $reserved): array
    {
        $problems = [];
        $names = [];
        $clicks = 0;

        if (count($params) > self::MOST) {
            $problems[] = ['index' => -1, 'field' => '', 'message' => 'A link carries at most '.self::MOST.' parameters.'];
        }

        foreach (array_values($params) as $index => $param) {
            $param = is_array($param) ? $param : [];
            $kind = TrafficLinkParam::tryFrom((string) ($param['kind'] ?? ''));
            $name = (string) ($param['param'] ?? '');
            $token = (string) ($param['token'] ?? '');
            $label = (string) ($param['label'] ?? '');

            if ($kind === null) {
                $problems[] = ['index' => $index, 'field' => 'kind', 'message' => 'Choose what this parameter carries.'];
            }

            $clicks += $kind === TrafficLinkParam::Click ? 1 : 0;

            if (preg_match('/^[a-z][a-z0-9_]{0,23}$/', $name) !== 1) {
                $problems[] = ['index' => $index, 'field' => 'param', 'message' => 'Use 1–24 lowercase letters, digits and _, starting with a letter.'];
            } elseif (str_starts_with($name, 'rg_') || in_array($name, $reserved, true)) {
                $problems[] = ['index' => $index, 'field' => 'param', 'message' => "“{$name}” is read by the site’s own pages. Use another name."];
            } elseif (in_array($name, $names, true)) {
                $problems[] = ['index' => $index, 'field' => 'param', 'message' => "“{$name}” is already used by another parameter."];
            }

            $names[] = $name;

            // The link carries a token raw, so one holding a separator of the address would split it.
            if ($token !== '' && (mb_strlen($token) > 120 || ! LinkTokenDetector::isToken($token) || preg_match('/[&=?\s]/', $token) === 1
                || (! str_starts_with($token, '##') && str_contains($token, '#')))) {
                $problems[] = ['index' => $index, 'field' => 'token', 'message' => 'A token looks like {name}, [NAME], ##NAME## or ${NAME}, as the network writes it.'];
            }

            if ($label === '' || mb_strlen($label) > 60) {
                $problems[] = ['index' => $index, 'field' => 'label', 'message' => 'Give the parameter a name of up to 60 characters.'];
            }

            if (mb_strlen((string) ($param['note'] ?? '')) > 200) {
                $problems[] = ['index' => $index, 'field' => 'note', 'message' => 'Keep the note to 200 characters.'];
            }
        }

        if ($clicks !== 1) {
            $problems[] = ['index' => -1, 'field' => '', 'message' => 'Exactly one parameter carries the click ID: postbacks need it.'];
        }

        return $problems;
    }
}
