<?php
// Set response header to JSON format
header('Content-Type: application/json');

// Prevent HTML error output breaking the front-end JSON parser
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Define data storage directory for audio and JSON metadata
$dataDir = __DIR__ . '/data';
if (!file_exists($dataDir)) {
    @mkdir($dataDir, 0777, true);
}

$action = $_GET['action'] ?? 'assess';

// -------------------------------------------------------------
// 1. GET Request: Retrieve all practice history
// -------------------------------------------------------------
if ($action === 'history') {
    $records = [];
    $files = glob($dataDir . '/*.json');
    
    if ($files !== false) {
        foreach ($files as $file) {
            $jsonContent = @file_get_contents($file);
            if ($jsonContent !== false) {
                $decoded = json_decode($jsonContent, true);
                if (is_array($decoded)) {
                    $records[] = $decoded;
                }
            }
        }
        
        // Sort records in descending order by timestamp
        usort($records, function ($a, $b) {
            return strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? '');
        });
    }
    
    echo json_encode($records);
    exit;
}

// -------------------------------------------------------------
// 2. POST Request: Evaluate German speech attempt
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $apiKey = $_POST['api_key'] ?? '';
    $parentId = $_POST['parent_id'] ?? null;

    if (empty($apiKey) || !isset($_FILES['audio'])) {
        http_response_code(400);
        echo json_encode(["error" => "Missing API Key or Audio file"]);
        exit;
    }

    $timestamp = date("Ymd_His");

    // Determine session ID and practice round suffix
    if (!empty($parentId)) {
        $rootId = explode('_', $parentId)[0];
        $existing = glob($dataDir . '/' . $rootId . '_*.json');
        $nextNum = ($existing !== false) ? count($existing) + 1 : 1;
        $sessionId = $rootId . '_' . $nextNum;
        $isRound2 = true;
    } else {
        $sessionId = $timestamp;
        $isRound2 = false;
    }

    $audioFilename = $sessionId . '.webm';
    $jsonFilename = $sessionId . '.json';
    $audioPath = $dataDir . '/' . $audioFilename;
    $jsonPath = $dataDir . '/' . $jsonFilename;

    // Save uploaded audio file to data directory
    if (!move_uploaded_file($_FILES['audio']['tmp_name'], $audioPath)) {
        http_response_code(500);
        echo json_encode(["error" => "Failed to save uploaded audio file on server"]);
        exit;
    }

    // Fetch previous round details to supply context for multi-round learning
    $previousContext = "";
    if ($isRound2 && !empty($parentId)) {
        $parentJsonPath = $dataDir . '/' . $parentId . '.json';
        if (file_exists($parentJsonPath)) {
            $prevData = json_decode(file_get_contents($parentJsonPath), true);
            if (is_array($prevData)) {
                $prevText = $prevData['transcription'] ?? '';
                $prevErrors = json_encode($prevData['grammar_analysis_de'] ?? []);
                $prevImproved = $prevData['improved_expression_de'] ?? '';
                $previousContext = "
                【PREVIOUS ROUND CONTEXT】
                User said earlier: \"{$prevText}\"
                Identified errors: {$prevErrors}
                Suggested correction: \"{$prevImproved}\"
                Please check if the user corrected their previous mistakes in this new recording!
                ";
            }
        }
    }

    // Build Multi-Dimensional System Prompt for Gemini
    $systemPrompt = "
    You are a professional German speaking examiner following the CEFR standard (A1-C1).
    {$previousContext}
    Analyze the audio and return a JSON object ONLY with the following keys:
    - transcription: (string) Recognized German text.
    - intent_summary_en: (string) Brief English summary of what the user tried to say.
    - detected_level: (string) CEFR level (\"A1\", \"A2\", \"B1\", \"B2\", or \"C1\").
    - complexity_score: (number 1-100) Evaluates sentence structure, vocabulary richness, and grammar sophistication based on CEFR level (A1: 1-20, A2: 21-40, B1: 41-60, B2: 61-80, C1: 81-100).
    - accuracy_score: (number 1-100) Grammatical correctness, case usage, verb placement, gender accuracy, and vocabulary precision.
    - fluency_score: (number 1-100) Speech flow, natural pauses, pronunciation clarity, and confidence.
    - grammar_analysis_de: (list of strings) Detailed error analysis in GERMAN.
    - improved_expression_de: (string) A natural, idiomatic German way to say it.
    - feedback_de: (string) Encouraging feedback in GERMAN explaining how to improve.
    ";

    // Encode audio file to Base64
    $audioData = base64_encode(file_get_contents($audioPath));

    $payload = [
        "contents" => [[
            "parts" => [
                ["text" => $systemPrompt],
                ["inline_data" => ["mime_type" => "audio/webm", "data" => $audioData]]
            ]
        ]],
        "generationConfig" => [
            "response_mime_type" => "application/json"
        ]
    ];

    // Send HTTP POST request to Gemini 2.5 Flash API via cURL
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3.6-flash:generateContent?key=" . $apiKey;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    
    // Ignore SSL certification checks locally if needed
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    // Handle cURL or API Failure
    if ($response === false || $httpCode !== 200) {
        if (file_exists($audioPath)) {
            unlink($audioPath);
        }

        http_response_code(500);
        echo json_encode([
            "error" => "Gemini API Request Failed", 
            "http_code" => $httpCode,
            "curl_error" => $curlError,
            "details" => json_decode($response, true) ?? $response
        ]);
        exit;
    }

    $resData = json_decode($response, true);
    $rawText = $resData['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
    $parsedResult = json_decode($rawText, true) ?? [];

    // Metrics extraction with fallbacks
    $complexity = $parsedResult['complexity_score'] ?? 30;
    $accuracy = $parsedResult['accuracy_score'] ?? 70;
    $fluency = $parsedResult['fluency_score'] ?? 70;

    $overallScore = round(($complexity * 0.4) + ($accuracy * 0.3) + ($fluency * 0.3));
    $overallScore = max(1, min(100, $overallScore));

    // Consolidate response data
    $recordData = array_merge([
        "id" => $sessionId,
        "parent_id" => $parentId,
        "round" => $isRound2 ? (substr_count($sessionId, '_') > 1 ? 3 : 2) : 1,
        "timestamp" => $timestamp,
        "audio_file" => $audioFilename,
        "overall_score" => $overallScore,
        "complexity_score" => $complexity,
        "accuracy_score" => $accuracy,
        "fluency_score" => $fluency
    ], $parsedResult);

    // Save output JSON file
    file_put_contents($jsonPath, json_encode($recordData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    echo json_encode($recordData);
    exit;
}