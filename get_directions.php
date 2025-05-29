<?php
// get_directions.php

require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

header('Content-Type: application/json');

// Получаем данные из POST-запроса
$input = json_decode(file_get_contents('php://input'), true);
$scores = array_map('intval', $input['scores'] ?? []);
$fullTime = $input['full_time'] ?? false;
$partTime = $input['part_time'] ?? false;
$correspondence = $input['correspondence'] ?? false;
$includeExtra = $input['include_extra'] ?? false;

// Чтение Excel-файла
$filePath = __DIR__ . '/files/План приема в СВФУ на 2025-2026 уч. г..xlsx';
if (!file_exists($filePath)) {
    echo json_encode(["error" => "Excel file not found."]);
    exit;
}

$spreadsheet = IOFactory::load($filePath);
$sheet = $spreadsheet->getActiveSheet();
$data = $sheet->toArray(null, true, true, true);

$headers = array_shift($data);
$rows = array_map(function($row) use ($headers) {
    return array_combine($headers, $row);
}, $data);

function parseSubjects($text) {
    if (!$text) return [];
    $parts = explode(';', $text);
    $mandatory = [];
    $optionalGroups = [];

    foreach ($parts as $part) {
        $part = trim($part);
        if (strpos($part, '/') !== false) {
            $options = array_map('trim', explode('/', $part));
            $group = [];
            foreach ($options as $opt) {
                if (preg_match('/(.+?)\s*-\s*(\d+)\s*\D?/', $opt, $m)) {
                    $group[] = [$m[1], (int)$m[2]];
                }
            }
            if ($group) $optionalGroups[] = $group;
        } else {
            if (preg_match('/(.+?)\s*-\s*(\d+)\s*\D?/', $part, $m)) {
                $mandatory[] = [$m[1], (int)$m[2]];
            }
        }
    }

    if (!$optionalGroups) return [$mandatory];

    $variants = [];
    foreach (cartesianProduct($optionalGroups) as $combo) {
        $variants[] = array_merge($mandatory, $combo);
    }

    return $variants;
}

function cartesianProduct($arrays) {
    $result = [[]];
    foreach ($arrays as $array) {
        $newResult = [];
        foreach ($result as $product) {
            foreach ($array as $item) {
                $newResult[] = array_merge($product, [$item]);
            }
        }
        $result = $newResult;
    }
    return $result;
}

function checkDirectionMatch($userScores, $subjectVariants, $includeExtra) {
    foreach ($subjectVariants as $variant) {
        $required = [];
        $extra = [];

        foreach ($variant as [$subj, $minScore]) {
            if (preg_match('/творческое|профессиональное|собеседование/iu', $subj)) {
                $extra[] = [$subj, $minScore];
            } else {
                $required[] = [$subj, $minScore];
            }
        }

        if (!$includeExtra && $extra) continue;

        $matched = 0;
        foreach ($required as [$subj, $minScore]) {
            if (isset($userScores[$subj]) && $userScores[$subj] >= $minScore) {
                $matched++;
            } elseif (strpos($subj, 'Иностранный язык') !== false && isset($userScores['Иностранный язык']) && $userScores['Иностранный язык'] >= $minScore) {
                $matched++;
            }
        }

        if ($matched === count($required)) return true;
    }

    return false;
}

function formatExams($examStr) {
    $parts = explode(';', $examStr);
    $formatted = array_map(function($p) {
        return preg_replace('/\s*\/\s*/', ' или ', trim($p));
    }, $parts);
    return implode("\n", $formatted);
}

$finalResults = [];
$uchpGroups = [];

foreach ($rows as $row) {
    $examField = 'Перечень вступительных испытаний для поступающих на базе СОО и минимальное количество баллов';
    if (empty($row[$examField])) continue;

    $parsedSubjects = parseSubjects($row[$examField]);
    if (!checkDirectionMatch($scores, $parsedSubjects, $includeExtra)) continue;

    $formOk = false;
    $formTypes = [];

    if ($fullTime) {
        $b = intval($row['Количество мест для приема на обучение по очной форме в рамках КЦП (бюджетные места)'] ?? 0);
        $p = intval($row['Количество мест для приема на обучение по очной форме по ДОПОУ (платный прием)'] ?? 0);
        if ($b + $p > 0) {
            $formOk = true;
            $formTypes['Очная форма'] = "$b бюджетных, $p платных";
        }
    }
    if ($partTime) {
        $b = intval($row['Количество мест для приема на обучение по очно-заочной форме в рамках КЦП (бюджетные места)'] ?? 0);
        $p = intval($row['Количество мест для приема на обучение по очно-заочной форме по ДОПОУ (платный прием)'] ?? 0);
        if ($b + $p > 0) {
            $formOk = true;
            $formTypes['Очно-заочная форма'] = "$b бюджетных, $p платных";
        }
    }
    if ($correspondence) {
        $b = intval($row['Количество мест для приема на обучение по заочной форме в рамках КЦП (бюджетные места)'] ?? 0);
        $p = intval($row['Количество мест для приема на обучение по заочной форме по ДОПОУ (платный прием)'] ?? 0);
        if ($b + $p > 0) {
            $formOk = true;
            $formTypes['Заочная форма'] = "$b бюджетных, $p платных";
        }
    }

    if (!$formOk) continue;

    $uchp = $row['УчП'] ?? 'Прочее';
    $uchpGroups[$uchp][] = [
        'code' => $row['Код НПС'] ?? '',
        'program' => $row['Наименование образовательной программы'] ?? '',
        'exams' => formatExams($row[$examField]),
        'places' => $formTypes
    ];
}

foreach ($uchpGroups as $uchp => $directions) {
    $finalResults[] = [
        'uchp_name' => $uchp,
        'directions' => $directions
    ];
}

echo json_encode([
    'scores' => $scores,
    'forms' => [
        'full_time' => $fullTime,
        'part_time' => $partTime,
        'correspondence' => $correspondence
    ],
    'results' => $finalResults
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

?>
