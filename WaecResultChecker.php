<?php
/**
 * WaecResultChecker — Official WAEC Direct Result Retrieval & Aggregate Calculation
 * 
 * Supports:
 * - WASSCE (School & Private)
 * - BECE (School & Private)
 * - Automatic parsing of Candidate, Index, School, Subjects & Grades
 * - Accurate Total Grade / Aggregate Calculation
 */

class WaecResultChecker {

    const PORTAL_URL = 'https://ghana.waecdirect.org/';
    const SUBMIT_URL = 'https://ghana.waecdirect.org/results.asp';

    /**
     * Check WAEC result from official portal
     * 
     * @param string $examType "WASSCE" or "BECE" (or "01", "08", "07", "09")
     * @param string $indexNumber 10-digit candidate index number
     * @param string $year 4-digit examination year
     * @param string $serial Card serial number
     * @param string $pin 12-digit card PIN
     * @return array
     */
    public static function checkResult(string $examType, string $indexNumber, string $year, string $serial, string $pin): array {
        $cleanIndex = preg_replace('/\D/', '', $indexNumber);
        $cleanYear  = preg_replace('/\D/', '', $year);
        $cleanSerial = strtoupper(trim($serial));
        $cleanPin   = preg_replace('/\D/', '', $pin);

        if (strlen($cleanIndex) !== 10) {
            return ['success' => false, 'message' => 'Invalid Index Number. WAEC Index Numbers must be exactly 10 digits (e.g. 0010101001).'];
        }
        if (strlen($cleanYear) !== 4) {
            return ['success' => false, 'message' => 'Invalid Examination Year. Please specify a 4-digit year (e.g. 2024).'];
        }
        if (empty($cleanSerial)) {
            return ['success' => false, 'message' => 'Card Serial Number is required (e.g. WSC12345678).'];
        }
        if (strlen($cleanPin) < 10) {
            return ['success' => false, 'message' => 'Card PIN must be 10 to 12 digits.'];
        }

        // Map exam type to portal code
        $typeCode = '01'; // Default: WASSCE School
        $typeName = 'W.A.S.S.C.E. (School)';
        $normType = strtoupper(trim($examType));

        if (strpos($normType, 'BECE') !== false) {
            if (strpos($normType, 'PRIV') !== false) {
                $typeCode = '09';
                $typeName = 'B.E.C.E. (Private)';
            } else {
                $typeCode = '07';
                $typeName = 'B.E.C.E.';
            }
        } elseif (strpos($normType, 'PRIV') !== false || strpos($normType, 'NOV') !== false) {
            $typeCode = '08';
            $typeName = 'W.A.S.S.C.E. (Private)';
        } else {
            $typeCode = '01';
            $typeName = 'W.A.S.S.C.E. (School)';
        }

        // 1. Try Browser Automation via Puppeteer (captures live portal screenshot & handles modal)
        $nodeScript = __DIR__ . '/../whatsapp_qr_bot/waec_checker.js';
        if (file_exists($nodeScript)) {
            $cmd = sprintf(
                'node %s %s %s %s %s %s %s 2>&1',
                escapeshellarg($nodeScript),
                escapeshellarg($typeName),
                escapeshellarg($typeCode),
                escapeshellarg($cleanIndex),
                escapeshellarg($cleanYear),
                escapeshellarg($cleanSerial),
                escapeshellarg($cleanPin)
            );
            $output = @shell_exec($cmd);
            if (!empty($output) && strpos($output, '--- RESULT JSON OUTPUT ---') !== false) {
                $jsonStr = substr($output, strpos($output, '--- RESULT JSON OUTPUT ---') + strlen('--- RESULT JSON OUTPUT ---'));
                $data = json_decode(trim($jsonStr), true);
                if (is_array($data) && (isset($data['success']) || isset($data['reply']))) {
                    if (!empty($data['image_path'])) {
                        $GLOBALS['waec_latest_image'] = $data['image_path'];
                    }
                    return $data;
                }
            }
        }

        // Temporary cookie file for ASP session (cURL fallback)
        $cookieFile = sys_get_temp_dir() . '/waec_sess_' . md5($cleanIndex . $cleanPin . microtime()) . '.txt';
        if (file_exists($cookieFile)) @unlink($cookieFile);

        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

        // 2. Initial GET to establish session cookies (cURL fallback)
        $ch = curl_init(self::PORTAL_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR      => $cookieFile,
            CURLOPT_COOKIEFILE     => $cookieFile,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => $ua
        ]);
        $homeHtml = curl_exec($ch);
        curl_close($ch);

        // 2. POST credentials to results.asp
        $postData = [
            'candid'    => $cleanIndex,
            'ccandid'   => $cleanIndex,
            'examtype'  => $typeCode,
            'examyear'  => $cleanYear,
            'cexamyear' => $cleanYear,
            'serial'    => $cleanSerial,
            'pin'       => $cleanPin,
            'referpage' => 'index.htm',
            'Submit'    => 'Submit'
        ];

        $ch = curl_init(self::SUBMIT_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($postData),
            CURLOPT_COOKIEJAR      => $cookieFile,
            CURLOPT_COOKIEFILE     => $cookieFile,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => [
                'Referer: ' . self::PORTAL_URL,
                'Origin: https://ghana.waecdirect.org',
                'User-Agent: ' . $ua,
                'Content-Type: application/x-www-form-urlencoded'
            ]
        ]);
        $resHtml = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if (file_exists($cookieFile)) @unlink($cookieFile);

        if ($curlErr) {
            return ['success' => false, 'message' => 'Network error connecting to WAEC Portal: ' . $curlErr];
        }

        if (empty($resHtml) || $httpCode >= 500) {
            return ['success' => false, 'message' => 'The WAEC Direct portal server is currently busy or down. Please try again in 5 minutes.'];
        }

        return self::parseWaecResponse($resHtml, $cleanIndex, $cleanYear, $typeName);
    }

    /**
     * Parse HTML output from WAEC results page
     */
    public static function parseWaecResponse(string $html, string $indexNumber, string $year, string $defaultExamType): array {
        // 1. Check for known WAEC error notices
        if (stripos($html, 'ERROR!!!') !== false || stripos($html, 'Access Violation') !== false || stripos($html, 'Unexpected Error') !== false || stripos($html, 'Invalid Card') !== false || stripos($html, 'Card Limit') !== false) {
            $errNotice = 'WAEC Portal Notice: ';
            $errHeading = '';
            if (preg_match('/<th[^>]*>[\s\S]*?<font[^>]*>[\s\S]*?<strong>(.*?)<\/strong>/is', $html, $hm)) {
                $errHeading = trim(strip_tags($hm[1]));
            } elseif (preg_match('/<strong>(Invalid Card[^<]*|Card Limit[^<]*)<\/strong>/is', $html, $hm2)) {
                $errHeading = trim(strip_tags($hm2[1]));
            }

            $errMsg = '';
            if (preg_match('/<font color="#000033"[^>]*>(.*?)<\/font>/is', $html, $m)) {
                $errMsg = trim(strip_tags($m[1]));
            } elseif (preg_match('/<td[^>]*bgcolor="#FFFFFF"[^>]*>(.*?)<\/td>/is', $html, $m2)) {
                $errMsg = trim(strip_tags($m2[1]));
            }

            // Remove any "Close this window" links from error text
            $errMsg = trim(preg_replace('/Close this window/i', '', $errMsg));

            if (!empty($errHeading) && !empty($errMsg)) {
                $errNotice .= "*{$errHeading}*\n" . $errMsg;
            } elseif (!empty($errMsg)) {
                $errNotice .= $errMsg;
            } elseif (!empty($errHeading)) {
                $errNotice .= "*{$errHeading}*";
            } else {
                $errNotice .= 'Invalid examination details, serial number, or card PIN. Please verify your checker credentials.';
            }
            return ['success' => false, 'message' => $errNotice];
        }

        // 2. Extract Candidate Name
        $candidateName = '';
        if (preg_match('/(?:Candidate Name|Name of Candidate|Candidate\'s Name)[:\s]*<[^>]*>([^<]+)/i', $html, $m)) {
            $candidateName = trim(strip_tags($m[1]));
        } elseif (preg_match('/(?:Candidate Name|Name of Candidate|Candidate\'s Name)[:\s]*([A-Z\s\.\-]{3,60})(?:<|\r|\n)/i', $html, $m)) {
            $candidateName = trim(strip_tags($m[1]));
        } elseif (preg_match('/Candidate Name[\s\S]*?<td[^>]*>([^<]+)<\/td>/i', $html, $m)) {
            $candidateName = trim(strip_tags($m[1]));
        }

        // Additional patterns for WAEC Ghana Direct portal:
        if (empty($candidateName) || strlen($candidateName) < 2) {
            if (preg_match('/<td[^>]*>\s*Candidate(?:\'s)?\s*Name\s*<\/td>\s*<td[^>]*>(.*?)<\/td>/is', $html, $m)) {
                $candidateName = trim(strip_tags($m[1]));
            } elseif (preg_match('/Candidate(?:\'s)?\s*Name\s*:\s*([^<\r\n]+)/i', $html, $m)) {
                $candidateName = trim(strip_tags($m[1]));
            }
        }

        $candidateName = trim(preg_replace('/\s+/', ' ', $candidateName));
        if (empty($candidateName) || strtolower($candidateName) === 'null' || strlen($candidateName) < 2) {
            $candidateName = "CANDIDATE ({$indexNumber})";
        }

        // 3. Extract School / Center Name
        $schoolName = '';
        if (preg_match('/(?:School|Centre|Center|School Name|Centre Name)[:\s]*<[^>]*>([^<]+)/i', $html, $m)) {
            $schoolName = trim(strip_tags($m[1]));
        } elseif (preg_match('/(?:School|Centre|Center|School Name|Centre Name)[:\s]*([A-Z0-9\s\.\-]{3,80})(?:<|\r|\n)/i', $html, $m)) {
            $schoolName = trim(strip_tags($m[1]));
        } elseif (preg_match('/(?:School|Centre)[\s\S]*?<td[^>]*>([^<]+)<\/td>/i', $html, $m)) {
            $schoolName = trim(strip_tags($m[1]));
        }

        if (empty($schoolName) || strlen($schoolName) < 2) {
            if (preg_match('/<td[^>]*>\s*(?:School|Centre|Center)\s*(?:Name)?\s*<\/td>\s*<td[^>]*>(.*?)<\/td>/is', $html, $m)) {
                $schoolName = trim(strip_tags($m[1]));
            }
        }

        $schoolName = trim(preg_replace('/\s+/', ' ', $schoolName));
        if (empty($schoolName) || strtolower($schoolName) === 'null' || $schoolName === 'N/A' || strlen($schoolName) < 2) {
            $schoolName = 'EXAMINATION CENTRE (GH)';
        }

        // 4. Extract Subjects and Grades
        $subjects = [];

        // Search for table rows containing subject and grade (e.g. <td>ENGLISH LANG</td><td>A1</td>)
        if (preg_match_all('/<tr[^>]*>\s*<td[^>]*>([A-Z\s\(\)\/\.,&-]{3,50})<\/td>\s*<td[^>]*>([A-F0-9]{1,3})<\/td>/i', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $row) {
                $subj = trim(strip_tags($row[1]));
                $grd  = strtoupper(trim(strip_tags($row[2])));
                if (!empty($subj) && !empty($grd) && !in_array(strtoupper($subj), ['SUBJECT', 'GRADE', 'REMARK', 'INDEX NO', 'CANDIDATE'])) {
                    $gradeInfo = self::getWaecGradeDetails($grd, $defaultExamType);
                    $subjects[] = [
                        'subject' => $subj,
                        'grade'   => $grd,
                        'points'  => $gradeInfo['points'],
                        'remark'  => $gradeInfo['remark']
                    ];
                }
            }
        }

        // If no subjects found via regex, check if page contains results table
        if (empty($subjects)) {
            // Check for raw text patterns (e.g., CORE MATHEMATICS A1)
            $text = strip_tags($html);
            $knownSubjects = [
                'ENGLISH LANG', 'CORE MATHEMATICS', 'MATHEMATICS(CORE)', 'MATHEMATICS', 'INTEGRATED SCIENCE', 'SOCIAL STUDIES',
                'PHYSICS', 'CHEMISTRY', 'BIOLOGY', 'ELECTIVE MATHS', 'MATHEMATICS(ELECT)', 'GEOGRAPHY', 'ECONOMICS',
                'GOVERNMENT', 'HISTORY', 'LIT-IN-ENGLISH', 'CHRISTIAN REL STUD', 'ISLAMIC REL STUD', 'FRENCH', 'GHANAIAN LANG',
                'GENERAL AGRIC', 'ANIMAL HUSBANDRY', 'FOODS & NUTRITION', 'MANAGEMENT IN LIVING', 'CLOTHING & TEXTILES',
                'VISUAL ARTS', 'PICTURE MAKING', 'GRAPHIC DESIGN', 'TEXTILES', 'CERAMICS', 'SCULPTURE',
                'BUSINESS MANAGEMENT', 'FINANCIAL ACCOUNTING', 'COST ACCOUNTING', 'PRINCIPLES OF COSTING', 'TYPEWRITING',
                'INFORMATION TECHNOLOGY', 'APPLIED ELECTRICITY', 'TECHNICAL DRAWING', 'AUTO MECHANICS', 'BUILDING CONSTRUCTION',
                'REL & MORAL EDUC', 'B.D.T.', 'COMPUTING', 'CAREER TECHNOLOGY', 'CREATIVE ARTS'
            ];

            foreach ($knownSubjects as $ks) {
                if (preg_match('/\b' . preg_quote($ks, '/') . '\b[\s:]*([A-F0-9]{1,2})/i', $text, $subMatch)) {
                    $grd = strtoupper(trim($subMatch[1]));
                    $gradeInfo = self::getWaecGradeDetails($grd, $defaultExamType);
                    $subjects[] = [
                        'subject' => $ks,
                        'grade'   => $grd,
                        'points'  => $gradeInfo['points'],
                        'remark'  => $gradeInfo['remark']
                    ];
                }
            }
        }

        if (empty($subjects)) {
            return [
                'success' => false,
                'message' => 'WAEC result could not be parsed. Please ensure the index number, serial number, and PIN are correct.'
            ];
        }

        // 5. Calculate Total Aggregate
        $aggregateData = self::calculateAggregate($subjects, $defaultExamType);

        $resData = [
            'success'        => true,
            'candidate_name' => $candidateName,
            'index_number'   => $indexNumber,
            'school_name'    => $schoolName,
            'exam_type'      => $defaultExamType,
            'exam_year'      => $year,
            'subjects'       => $subjects,
            'total_grade'      => $aggregateData['total_aggregate'],
            'aggregate_text'   => $aggregateData['label'],
            'remarks'          => $aggregateData['remarks'],
            'best_6_subjects'  => $aggregateData['best_6_subjects'] ?? [],
            'breakdown'        => $aggregateData['breakdown'] ?? ''
        ];

        // 6. Generate Official WAEC Result Slip PDF & Web Printing Links
        try {
            require_once __DIR__ . '/WaecPdfGenerator.php';
            $pdfInfo = WaecPdfGenerator::generateSlipFile($resData);
            $resData['pdf_file'] = $pdfInfo['file_path'];
            $resData['pdf_url']  = $pdfInfo['file_url'];
            $resData['pdf_name'] = $pdfInfo['file_name'];

            $baseUrl = (defined('APP_URL') && APP_URL) ? rtrim(APP_URL, '/') : '';
            $resData['print_url'] = $baseUrl . "/result_slip.php?index={$indexNumber}&year={$year}";

            // Cache JSON for web viewer
            $saveDir = __DIR__ . '/../uploads/results';
            if (!is_dir($saveDir)) @mkdir($saveDir, 0755, true);
            @file_put_contents("{$saveDir}/WAEC_Result_{$indexNumber}_{$year}.json", json_encode($resData, JSON_PRETTY_PRINT));

            // Set global so WhatsAppBot and bridge know about the generated PDF
            $GLOBALS['waec_latest_pdf'] = [
                'file_path'      => $pdfInfo['file_path'],
                'file_name'      => $pdfInfo['file_name'],
                'file_url'       => $pdfInfo['file_url'],
                'print_url'      => $resData['print_url'],
                'candidate_name' => $candidateName,
                'index_number'   => $indexNumber,
                'school_name'    => $schoolName
            ];
        } catch (Throwable $pe) {}

        return $resData;
    }

    /**
     * Map grade code to numerical points
     */
    public static function gradeToPoints(string $grade): int {
        $details = self::getWaecGradeDetails($grade);
        return $details['points'] ?? 9;
    }

    /**
     * Official WAEC Ghana Grading System Details
     */
    public static function getWaecGradeDetails(string $grade, string $examType = 'WASSCE'): array {
        $g = strtoupper(trim($grade));
        $isBece = (strpos(strtoupper($examType), 'BECE') !== false);

        if ($isBece) {
            $beceMap = [
                '1' => ['points' => 1, 'remark' => 'Highest / Distinction', 'level' => 'Distinction'],
                '2' => ['points' => 2, 'remark' => 'Higher / Distinction', 'level' => 'Distinction'],
                '3' => ['points' => 3, 'remark' => 'High / Good', 'level' => 'Good'],
                '4' => ['points' => 4, 'remark' => 'High Average / Credit', 'level' => 'Credit'],
                '5' => ['points' => 5, 'remark' => 'Average / Credit', 'level' => 'Credit'],
                '6' => ['points' => 6, 'remark' => 'Low Average / Credit', 'level' => 'Credit'],
                '7' => ['points' => 7, 'remark' => 'Low / Pass', 'level' => 'Pass'],
                '8' => ['points' => 8, 'remark' => 'Lower / Pass', 'level' => 'Pass'],
                '9' => ['points' => 9, 'remark' => 'Lowest / Fail', 'level' => 'Fail'],
            ];
            return $beceMap[$g] ?? ['points' => 9, 'remark' => 'Fail', 'level' => 'Fail'];
        } else {
            $wassceMap = [
                'A1' => ['points' => 1, 'remark' => 'Excellent', 'range' => '75% - 100%'],
                'B2' => ['points' => 2, 'remark' => 'Very Good', 'range' => '70% - 74%'],
                'B3' => ['points' => 3, 'remark' => 'Good', 'range' => '65% - 69%'],
                'C4' => ['points' => 4, 'remark' => 'Credit', 'range' => '60% - 64%'],
                'C5' => ['points' => 5, 'remark' => 'Credit', 'range' => '55% - 59%'],
                'C6' => ['points' => 6, 'remark' => 'Credit', 'range' => '50% - 54%'],
                'D7' => ['points' => 7, 'remark' => 'Pass', 'range' => '45% - 49%'],
                'E8' => ['points' => 8, 'remark' => 'Pass', 'range' => '40% - 44%'],
                'F9' => ['points' => 9, 'remark' => 'Fail', 'range' => '0% - 39%'],
            ];
            return $wassceMap[$g] ?? ['points' => 9, 'remark' => 'Fail', 'range' => '0% - 39%'];
        }
    }

    /**
     * Calculate Total Aggregate for BECE or WASSCE
     */
    /**
     * Calculate Total Aggregate for BECE or WASSCE using official Best 6 Subjects Rule
     * 
     * - WASSCE: Must include Core English, Core Maths, Integrated Science/Social Studies + 3 Best Electives
     * - BECE: Must include Core English, Mathematics, Integrated Science, Social Studies + 2 Best of the Rest
     * - Partial / Remedial Slips (< 6 subjects sat): Calculates aggregate of actual sat subjects without injecting fake F9s
     */
    public static function calculateAggregate(array $subjects, string $examType): array {
        $cleanType = str_replace('.', '', strtoupper($examType));
        $isBece = (strpos($cleanType, 'BECE') !== false);
        $numSubjects = count($subjects);

        // CASE 1: Partial / Remedial sitting (fewer than 6 subjects on slip)
        if ($numSubjects < 6) {
            $sorted = $subjects;
            usort($sorted, function($a, $b) {
                return ($a['points'] ?? 9) <=> ($b['points'] ?? 9);
            });

            $total = 0;
            $breakdownParts = [];
            $bestList = [];

            foreach ($sorted as $s) {
                $name = $s['subject'];
                $pts  = (int)($s['points'] ?? 9);
                $grd  = strtoupper(trim($s['grade'] ?? ''));
                $total += $pts;

                $shortName = preg_replace('/\s*\(.*?\)/', '', $name);
                $breakdownParts[] = "{$shortName} ({$pts})";

                $bestList[] = [
                    'subject' => $name,
                    'grade'   => $grd,
                    'points'  => $pts,
                    'type'    => 'Subject Sat'
                ];
            }

            $formatted = str_pad((string)$total, 2, '0', STR_PAD_LEFT);
            $breakdown = implode(' + ', $breakdownParts) . " = Aggregate {$formatted} ({$numSubjects} Subjects Sat)";

            $avg = $total / max(1, $numSubjects);
            if ($avg <= 2.5) {
                $remark = "Excellent Performance — Aggregate {$formatted} in {$numSubjects} subjects sat (Partial / Remedial Sitting)";
            } elseif ($avg <= 4.0) {
                $remark = "Very Good / Credit — Aggregate {$formatted} in {$numSubjects} subjects sat (Partial / Remedial Sitting)";
            } elseif ($avg <= 6.0) {
                $remark = "Pass / Credit — Aggregate {$formatted} in {$numSubjects} subjects sat (Partial / Remedial Sitting)";
            } else {
                $remark = "Pass — Aggregate {$formatted} in {$numSubjects} subjects sat (Partial / Remedial Sitting)";
            }

            return [
                'total_aggregate'  => "Aggregate {$formatted} ({$numSubjects} Subjects Sat)",
                'aggregate_number' => $total,
                'label'            => "Aggregate: {$formatted} ({$numSubjects} Subjects Sat)",
                'remarks'          => $remark,
                'best_6_subjects'  => $bestList,
                'breakdown'        => $breakdown,
                'is_partial'       => true,
                'subjects_sat'     => $numSubjects
            ];
        }

        // CASE 2: BECE Standard Best 6 (4 Cores + 2 Best of the Rest)
        if ($isBece) {
            $coreEng = null;
            $coreMath = null;
            $coreSci = null;
            $coreSoc = null;
            $restSubjects = [];

            foreach ($subjects as $s) {
                $name = strtoupper(trim($s['subject']));
                $pts = (int)($s['points'] ?? 9);
                $grd = strtoupper(trim($s['grade'] ?? '9'));

                if ($coreEng === null && strpos($name, 'ENGLISH') !== false) {
                    $coreEng = ['subject' => $s['subject'], 'grade' => $grd, 'points' => $pts, 'type' => 'Core English'];
                } elseif ($coreMath === null && (strpos($name, 'MATH') !== false || strpos($name, 'ARITHMETIC') !== false)) {
                    $coreMath = ['subject' => $s['subject'], 'grade' => $grd, 'points' => $pts, 'type' => 'Core Mathematics'];
                } elseif ($coreSci === null && strpos($name, 'SCIENCE') !== false) {
                    $coreSci = ['subject' => $s['subject'], 'grade' => $grd, 'points' => $pts, 'type' => 'Integrated Science'];
                } elseif ($coreSoc === null && strpos($name, 'SOCIAL') !== false) {
                    $coreSoc = ['subject' => $s['subject'], 'grade' => $grd, 'points' => $pts, 'type' => 'Social Studies'];
                } else {
                    $restSubjects[] = ['subject' => $s['subject'], 'grade' => $grd, 'points' => $pts, 'type' => 'Elective / Other'];
                }
            }

            $selected = [];
            if ($coreEng)  $selected[] = $coreEng;
            if ($coreMath) $selected[] = $coreMath;
            if ($coreSci)  $selected[] = $coreSci;
            if ($coreSoc)  $selected[] = $coreSoc;

            usort($restSubjects, function($a, $b) { return $a['points'] <=> $b['points']; });

            while (count($selected) < 6 && !empty($restSubjects)) {
                $selected[] = array_shift($restSubjects);
            }

            $total = array_sum(array_column($selected, 'points'));
            $formatted = str_pad((string)$total, 2, '0', STR_PAD_LEFT);

            $remark = ($total <= 12) ? 'Grade 1 / Distinction — Top Category A SHS Placement' :
                     (($total <= 20) ? 'Very Good — Category A & B SHS Placement' :
                     (($total <= 30) ? 'Pass — Qualified for SHS / TVET Placement' : 'Pass / Needs Improvement'));

            $breakdownParts = [];
            foreach ($selected as $b) {
                $shortName = preg_replace('/\s*\(.*?\)/', '', $b['subject']);
                $breakdownParts[] = "{$shortName} ({$b['points']})";
            }
            $breakdown = implode(' + ', $breakdownParts) . " = Aggregate {$formatted}";

            return [
                'total_aggregate'  => "Aggregate {$formatted}",
                'aggregate_number' => $total,
                'label'            => "Best 6 Subjects Aggregate: {$formatted}",
                'remarks'          => $remark,
                'best_6_subjects'  => $selected,
                'breakdown'        => $breakdown,
                'is_partial'       => false,
                'subjects_sat'     => $numSubjects
            ];
        }

        // CASE 3: WASSCE Standard Best 6 (Core English, Core Maths, Integrated Science/Social Studies + 3 Best Electives)
        $coreEng = null;
        $coreMath = null;
        $coreSci = null;
        $coreSoc = null;
        $electives = [];

        foreach ($subjects as $s) {
            $name = strtoupper(trim($s['subject']));
            $pts = (int)($s['points'] ?? 9);
            $grd = strtoupper(trim($s['grade'] ?? 'F9'));

            // Core English
            if ($coreEng === null && strpos($name, 'ENGLISH') !== false) {
                $coreEng = ['subject' => $s['subject'], 'grade' => $grd, 'points' => $pts, 'type' => 'Core English'];
            }
            // Core Mathematics (NOT Elective Maths)
            elseif ($coreMath === null && (strpos($name, 'MATH') !== false || strpos($name, 'ARITHMETIC') !== false) 
                    && strpos($name, 'ELECT') === false && strpos($name, 'FURTHER') === false) {
                $coreMath = ['subject' => $s['subject'], 'grade' => $grd, 'points' => $pts, 'type' => 'Core Mathematics'];
            }
            // Integrated Science (NOT Physics, Chemistry, Biology, Agriculture)
            elseif ($coreSci === null && (strpos($name, 'INTEG') !== false || ($name === 'SCIENCE' || $name === 'GENERAL SCIENCE'))
                    && strpos($name, 'PHYS') === false && strpos($name, 'CHEM') === false && strpos($name, 'BIO') === false) {
                $coreSci = ['subject' => $s['subject'], 'grade' => $grd, 'points' => $pts, 'type' => 'Integrated Science'];
            }
            // Social Studies (serves as core or elective)
            elseif ($coreSoc === null && strpos($name, 'SOCIAL') !== false) {
                $coreSoc = ['subject' => $s['subject'], 'grade' => $grd, 'points' => $pts, 'type' => 'Social Studies'];
            }
            // Everything else is an Elective
            else {
                $electives[] = ['subject' => $s['subject'], 'grade' => $grd, 'points' => $pts, 'type' => 'Elective'];
            }
        }

        // If candidate has both Science and Social Studies, pick the better one as 3rd Core, and the other joins Electives!
        $thirdCore = null;
        if ($coreSci && $coreSoc) {
            if ($coreSci['points'] <= $coreSoc['points']) {
                $thirdCore = $coreSci;
                $coreSoc['type'] = 'Elective (Social Studies)';
                $electives[] = $coreSoc;
            } else {
                $thirdCore = $coreSoc;
                $coreSci['type'] = 'Elective (Integrated Science)';
                $electives[] = $coreSci;
            }
        } elseif ($coreSci) {
            $thirdCore = $coreSci;
        } elseif ($coreSoc) {
            $thirdCore = $coreSoc;
        }

        // Selected Best 6: 3 Cores + Best Electives
        $selected = [];
        if ($coreEng)   $selected[] = $coreEng;
        if ($coreMath)  $selected[] = $coreMath;
        if ($thirdCore) $selected[] = $thirdCore;

        // Sort electives by points ascending
        usort($electives, function($a, $b) { return $a['points'] <=> $b['points']; });

        while (count($selected) < 6 && !empty($electives)) {
            $selected[] = array_shift($electives);
        }

        $total = array_sum(array_column($selected, 'points'));
        $formatted = str_pad((string)$total, 2, '0', STR_PAD_LEFT);

        $remark = ($total <= 9)  ? 'Outstanding / Distinction — Qualified for Competitive Programmes (Medicine, Engineering, Law)' :
                 (($total <= 15) ? 'Very Good / Distinction — Qualified for Top University Programmes' :
                 (($total <= 24) ? 'Good / Credit — Qualified for Tertiary Degree Admissions' :
                 (($total <= 30) ? 'Pass — Qualified for Diploma / Technical University Admissions' : 'Pass / Needs Improvement')));

        $breakdownParts = [];
        foreach ($selected as $b) {
            $shortName = preg_replace('/\s*\(.*?\)/', '', $b['subject']);
            $breakdownParts[] = "{$shortName} ({$b['points']})";
        }
        $breakdown = implode(' + ', $breakdownParts) . " = Aggregate {$formatted}";

        return [
            'total_aggregate'  => "Aggregate {$formatted}",
            'aggregate_number' => $total,
            'label'            => "Best 6 Subjects Aggregate: {$formatted}",
            'remarks'          => $remark,
            'best_6_subjects'  => $selected,
            'breakdown'        => $breakdown,
            'is_partial'       => false,
            'subjects_sat'     => $numSubjects
        ];
    }

    /**
     * Format result slip for WhatsApp delivery
     */
    public static function formatResultSlip(array $res): string {
        $candidateName = strtoupper(trim($res['candidate_name'] ?? ''));
        if (empty($candidateName) || strtolower($candidateName) === 'null') {
            $candidateName = 'CANDIDATE (' . ($res['index_number'] ?? 'STUDENT') . ')';
        }
        $indexNumber   = $res['index_number'] ?? 'N/A';
        $schoolName    = strtoupper(trim($res['school_name'] ?? ''));
        if (empty($schoolName) || $schoolName === 'N/A' || strtolower($schoolName) === 'null') {
            $schoolName = 'EXAMINATION CENTRE (GH)';
        }
        $examType      = $res['exam_type'] ?? 'WASSCE';
        $examYear      = $res['exam_year'] ?? date('Y');
        $totalGrade    = $res['total_grade'] ?? 'N/A';
        $remarks       = $res['remarks'] ?? 'Passed';
        $isBece        = (strpos(str_replace('.', '', strtoupper($examType)), 'BECE') !== false);
        $best6         = $res['best_6_subjects'] ?? [];
        $breakdown     = $res['breakdown'] ?? '';
        $numSubjects   = count($res['subjects'] ?? []);
        $isPartial     = !empty($res['is_partial']) || ($numSubjects < 6);

        $out = "*OFFICIAL WAEC PROVISIONAL RESULT SLIP*\n";
        $out .= "━━━━━━━━━━━━━━━━━━━━━\n";
        $out .= "*Candidate Name*: *" . $candidateName . "*\n";
        $out .= "🆔 *Index Number*: `" . $indexNumber . "`\n";
        if (!empty($schoolName) && $schoolName !== 'N/A') {
            $out .= "*School / Centre*: *" . $schoolName . "*\n";
        }
        $out .= "*Examination*: *" . $examType . " " . $examYear . "*\n";
        $out .= "━━━━━━━━━━━━━━━━━━━━━\n";
        $out .= "*STATEMENT OF RESULTS (WAEC GRADING)*:\n\n";

        foreach ($res['subjects'] as $s) {
            $grd = strtoupper(trim($s['grade']));
            $pts = $s['points'] ?? self::gradeToPoints($grd);
            $rem = strtoupper(trim($s['remark'] ?? ''));

            if (empty($rem)) {
                $rem = strtoupper(self::getWaecGradeDetails($grd, $examType)['remark'] ?? 'Pass');
            }

            $badge = '';

            $out .= "{$badge} *" . $s['subject'] . "*: *" . $grd . "* — " . $rem . " _(Pt " . $pts . ")_\n";
        }

        $out .= "\n━━━━━━━━━━━━━━━━━━━━━\n";
        $out .= "*TOTAL PERFORMANCE*: *" . $totalGrade . "*\n";
        $out .= "*Academic Evaluation*: " . $remarks . "\n";

        if (!empty($best6)) {
            $out .= "━━━━━━━━━━━━━━━━━━━━━\n";
            $secTitle = $isPartial
                ? "*Subjects Summary ({$numSubjects} Courses Sat)*:"
                : ($isBece ? "*BECE Best 6 Courses (4 Cores + 2 Best Rest)*:" : "*WASSCE Best 6 Courses (English, Maths, Science + 3 Best Electives)*:");
            $out .= $secTitle . "\n";
            foreach ($best6 as $b) {
                $typeLabel = !empty($b['type']) ? " (" . $b['type'] . ")" : "";
                $out .= "• *" . $b['subject'] . "*{$typeLabel}: Grade *" . $b['grade'] . "* (Pt *" . $b['points'] . "*)\n";
            }
            if (!empty($breakdown)) {
                $out .= "*Calculation*: " . $breakdown . "\n";
            }
        }

        $out .= "━━━━━━━━━━━━━━━━━━━━━\n";

        if (!empty($res['print_url']) || !empty($res['pdf_url'])) {
            $out .= "*OFFICIAL PRINTABLE PDF SLIP*:\n";
            if (!empty($res['pdf_url'])) {
                $out .= "*Direct PDF*: " . $res['pdf_url'] . "\n";
            }
            if (!empty($res['print_url'])) {
                $out .= "*Print & View Online*: " . $res['print_url'] . "\n";
            }
            $out .= "━━━━━━━━━━━━━━━━━━━━━\n";
        }

        $out .= "Verified via Apex Prime WAEC Direct.\n";
        $out .= "Official WAEC Portal: https://ghana.waecdirect.org/";

        return $out;
    }
}
