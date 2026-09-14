/**
 * Apex Prime — WAEC Direct & eResults Dual Browser Automation Checker
 * 
 * Supports:
 * 1. BECE (School & Private) -> Official eResults portal: https://eresults.waecgh.org/
 * 2. WASSCE (School & Private) -> Official WAEC Direct portal: https://ghana.waecdirect.org/
 * 
 * Automates real browser interaction, confirms dialogs, captures high-res screenshots
 * of official result slips or official error/verification notices, extracts candidate
 * grades & aggregates, and delivers image + text directly to WhatsApp.
 */

const fs = require('fs');
const path = require('path');

let puppeteer = null;
try {
    puppeteer = require('puppeteer-core');
} catch (e) {
    try {
        puppeteer = require(path.join(__dirname, 'node_modules', 'puppeteer-core'));
    } catch (e2) {
        try {
            puppeteer = require(path.join(__dirname, '../whatsapp_qr_bot/node_modules/puppeteer-core'));
        } catch (e3) {}
    }
}

let convertImageToResultPdf = null;
try {
    const pdfMod = require('./pdf_converter.js');
    convertImageToResultPdf = pdfMod.convertImageToResultPdf;
} catch (e) {}

function findChromePath() {
    const candidatePaths = [
        'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
        process.env.LOCALAPPDATA ? path.join(process.env.LOCALAPPDATA, 'Google', 'Chrome', 'Application', 'chrome.exe') : null,
        process.env.PROGRAMFILES ? path.join(process.env.PROGRAMFILES, 'Google', 'Chrome', 'Application', 'chrome.exe') : null,
        '/usr/bin/google-chrome',
        '/usr/bin/google-chrome-stable',
        '/usr/bin/chromium-browser',
        '/usr/bin/chromium'
    ].filter(Boolean);

    for (const p of candidatePaths) {
        if (fs.existsSync(p)) return p;
    }
    return null;
}

function getGradePoints(gradeStr) {
    if (!gradeStr) return null;
    const g = gradeStr.toUpperCase().trim();
    if (g === 'A1' || g === '1') return 1;
    if (g === 'B2' || g === '2') return 2;
    if (g === 'B3' || g === '3') return 3;
    if (g === 'C4' || g === '4') return 4;
    if (g === 'C5' || g === '5') return 5;
    if (g === 'C6' || g === '6') return 6;
    if (g === 'D7' || g === '7') return 7;
    if (g === 'E8' || g === '8') return 8;
    if (g === 'F9' || g === '9') return 9;
    return null;
}

/**
 * Check BECE Results via https://eresults.waecgh.org/
 */
async function checkBeceEresults(browser, cleanIndex, cleanYear, isPrivate, cleanSerial, cleanPin, screenshotFilePath, resultsDir) {
    console.log(`[WAEC eResults] Navigating to https://eresults.waecgh.org/ for BECE Index: ${cleanIndex}...`);
    const page = await browser.newPage();
    await page.setViewport({ width: 1280, height: 1000 });
    await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36');

    const examTypeStr = isPrivate ? 'BECE (Private)' : 'BECE (School)';
    const examCode = isPrivate ? 'PBEC' : 'BECE';

    await page.goto('https://eresults.waecgh.org/', { waitUntil: 'networkidle2', timeout: 40000 });

    // Fill form
    await page.waitForSelector('#form-indexnum', { timeout: 15000 });
    await page.type('#form-indexnum', cleanIndex, { delay: 20 });
    await page.type('#form-cindexnum', cleanIndex, { delay: 20 });

    // Select Exam Type
    await page.select('#form-examtype', examCode);

    // Select Year
    await page.select('#form-examyear', cleanYear);

    // Handle Serial mask
    let rawSerial = cleanSerial.replace(/[^A-Za-z0-9]/g, '');
    await page.type('#form-csn', rawSerial, { delay: 15 });

    // Handle PIN mask
    let rawPin = cleanPin.replace(/[^A-Za-z0-9]/g, '');
    await page.type('#form-pin', rawPin, { delay: 15 });

    console.log('[WAEC eResults] Submitting form...');
    await page.click('#form-submit');

    // Wait for response or modal popup
    try {
        await Promise.race([
            page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 15000 }),
            page.waitForSelector('.swal2-modal, .swal2-container, .alert, .table', { timeout: 12000 })
        ]);
    } catch (e) {}

    await new Promise(r => setTimeout(r, 2000));

    // Capture screenshot of results or alert modal
    await page.screenshot({ path: screenshotFilePath, fullPage: true });
    console.log(`[WAEC eResults] Saved screenshot to: ${screenshotFilePath}`);

    // PDF generation for the BECE result slip
    const pdfFileName = `BECE_Result_${cleanIndex}_${cleanYear}.pdf`;
    const pdfFilePath = resultsDir ? path.resolve(resultsDir, pdfFileName) : null;
    try {
        if (pdfFilePath) {
            await page.pdf({
                path: pdfFilePath,
                format: 'A4',
                printBackground: true,
                margin: {
                    top: '10mm',
                    right: '10mm',
                    bottom: '10mm',
                    left: '10mm'
                }
            });
            console.log(`[WAEC eResults] Generated official BECE PDF document: ${pdfFilePath}`);
        }
    } catch (pdfErr) {
        console.warn(`[WAEC eResults] PDF export notice:`, pdfErr.message);
    }

    const extracted = await page.evaluate(() => {
        const swal = document.querySelector('.swal2-modal, .swal2-popup');
        const alertEl = document.querySelector('.alert, .alert-danger, .alert-warning');
        const bodyText = document.body ? document.body.innerText : '';
        const hasTable = Boolean(document.querySelector('table'));
        return {
            swalText: swal ? swal.innerText.trim() : '',
            alertText: alertEl ? alertEl.innerText.trim() : '',
            bodyText: bodyText.substring(0, 3000),
            hasTable,
            url: window.location.href
        };
    });

    const isSwalError = Boolean(extracted.swalText && (extracted.swalText.includes('Ops!') || extracted.swalText.includes('invalid') || extracted.swalText.includes('used')));
    const isAlertError = Boolean(extracted.alertText && extracted.alertText.length > 5);
    const isErrorText = extracted.bodyText.includes('must be at least') || extracted.bodyText.includes('invalid') || extracted.bodyText.includes('Ops!');

    if (isSwalError || isAlertError || (isErrorText && !extracted.hasTable)) {
        let errorTitle = 'WAEC eResults Notice';
        let errorMsg = extracted.swalText || extracted.alertText;
        if (!errorMsg) {
            const lines = extracted.bodyText.split('\n').map(l => l.trim()).filter(Boolean);
            errorMsg = lines.find(l => l.includes('Ops!') || l.includes('invalid') || l.includes('must be')) || 'Invalid Card Serial or PIN.';
        }
        errorMsg = errorMsg.replace(/\bOk\b/g, '').trim();

        const reply = `❌ *WAEC BECE eRESULTS NOTICE*\n` +
            `━━━━━━━━━━━━━━━━━━━━━\n` +
            `⚠️ *Portal:* https://eresults.waecgh.org/\n` +
            `⚠️ *${errorTitle}*\n` +
            `${errorMsg}\n\n` +
            `📋 *Submitted Details*:\n` +
            `• Exam: *${examTypeStr} ${cleanYear}*\n` +
            `• Index Number: \`${cleanIndex}\`\n` +
            `• Voucher Serial: \`${cleanSerial}\`\n` +
            `━━━━━━━━━━━━━━━━━━━━━\n` +
            `💳 *Need a genuine BECE Result Checker Card?*\n` +
            `Buy instantly with instant card PIN & Serial at:\n` +
            `👉 https://apexprime.club/digital_store\n` +
            `👉 Or reply *2* to buy directly`;

        return {
            success: false,
            is_error_page: true,
            portal: 'https://eresults.waecgh.org/',
            error_title: errorTitle,
            error_message: errorMsg,
            image_path: screenshotFilePath,
            image_file: screenshotFilePath,
            reply
        };
    }

    // Parse valid BECE result
    let candidateName = '';
    let schoolName = '';
    const nameMatch = extracted.bodyText.match(/(?:Candidate Name|Name)[:\s]*([A-Z\s\.\-]{3,60})/i);
    if (nameMatch) candidateName = nameMatch[1].trim();
    if (!candidateName) candidateName = `CANDIDATE (${cleanIndex})`;

    const schoolMatch = extracted.bodyText.match(/(?:School|Centre)[:\s]*([A-Z0-9\s\.\-]{3,80})/i);
    if (schoolMatch) schoolName = schoolMatch[1].trim();

    // Subject breakdown
    const subjectRegex = /([A-Z\s\/\(\)]+?)\s+([1-9]|A1|B2|B3|C4|C5|C6|D7|E8|F9)\s+([A-Z\s]+)/gi;
    const subjects = [];
    let sm;
    while ((sm = subjectRegex.exec(extracted.bodyText)) !== null) {
        const sub = sm[1].trim();
        const grd = sm[2].trim();
        const rmk = sm[3].trim();
        if (sub.length >= 3 && !['EXAMINATION', 'INDEX', 'CANDIDATE', 'DATE OF BIRTH'].includes(sub.toUpperCase())) {
            subjects.push({ subject: sub, grade: grd, remark: rmk });
        }
    }

    let aggregateStr = '';
    if (subjects.length >= 6) {
        const scored = [];
        for (const s of subjects) {
            const pts = getGradePoints(s.grade);
            if (pts !== null) scored.push({ subject: s.subject, pts });
        }
        if (scored.length >= 6) {
            scored.sort((a, b) => a.pts - b.pts);
            const best6 = scored.slice(0, 6);
            const tot = best6.reduce((acc, c) => acc + c.pts, 0);
            aggregateStr = `\n🏆 *Calculated Aggregate*: *${String(tot).padStart(2, '0')}* (Best 6 Subjects)\n`;
        }
    }

    let subjectList = '';
    for (const s of subjects) {
        subjectList += `• ${s.subject}: *${s.grade}* (${s.remark})\n`;
    }

    const successReply = `🎓 *WEST AFRICAN EXAMINATIONS COUNCIL* 🎓\n` +
        `*OFFICIAL BECE PROVISIONAL RESULTS SLIP*\n` +
        `━━━━━━━━━━━━━━━━━━━━━\n` +
        `👤 *Candidate Name*: *${candidateName}*\n` +
        `🔢 *Index Number*: \`${cleanIndex}\`\n` +
        `🏫 *Examination*: *${examTypeStr} ${cleanYear}*\n` +
        (schoolName ? `📍 *School / Centre*: *${schoolName}*\n` : '') +
        `━━━━━━━━━━━━━━━━━━━━━\n` +
        `📊 *SUBJECT RESULTS*:\n` +
        (subjectList || 'See the attached official result slip image.\n') +
        `━━━━━━━━━━━━━━━━━━━━━` +
        `${aggregateStr}` +
        `✅ *Captured live from WAEC eResults Portal (https://eresults.waecgh.org/)*\n` +
        `━━━━━━━━━━━━━━━━━━━━━\n` +
        `_Powered by Apex Prime Tech (0553381853)_`;

    // Generate high-resolution PDF document from the official result slip screenshot
    if (convertImageToResultPdf && pdfFilePath) {
        try {
            await convertImageToResultPdf(screenshotFilePath, pdfFilePath, candidateName, cleanIndex, cleanYear);
            console.log(`[WAEC eResults] Formatted official result slip PDF generated: ${pdfFilePath}`);
        } catch (e) {
            console.warn(`[WAEC eResults] Error formatting PDF slip:`, e.message);
        }
    }

    return {
        success: true,
        candidate_name: candidateName,
        index_number: cleanIndex,
        exam_type: examTypeStr,
        exam_year: cleanYear,
        school_name: schoolName,
        subjects,
        image_path: screenshotFilePath,
        image_file: screenshotFilePath,
        pdf_file: (pdfFilePath && fs.existsSync(pdfFilePath)) ? pdfFilePath : null,
        pdf_name: pdfFileName,
        reply: successReply
    };
}

/**
 * Check WASSCE Results via https://ghana.waecdirect.org/
 */
async function checkWassceDirect(browser, cleanIndex, cleanYear, typeCode, examTypeName, cleanSerial, cleanPin, screenshotFilePath, resultsDir) {
    console.log(`[WAEC Direct] Navigating to https://ghana.waecdirect.org/ for ${cleanIndex}...`);
    const page = await browser.newPage();
    await page.setViewport({ width: 1280, height: 1000 });
    await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36');

    // Listen for new popup window if WAEC opens target="results"
    const popupPromise = new Promise((resolve) => {
        const timer = setTimeout(() => resolve(null), 10000);
        browser.on('targetcreated', async (target) => {
            if (target.type() === 'page') {
                clearTimeout(timer);
                const newPage = await target.page();
                resolve(newPage);
            }
        });
    });

    await page.goto('https://ghana.waecdirect.org/', { waitUntil: 'networkidle2', timeout: 40000 });

    console.log('[WAEC Direct] Filling credentials into form...');
    await page.type('#candid', cleanIndex, { delay: 15 });
    await page.type('#ccandid', cleanIndex, { delay: 15 });
    await page.select('#examtype', typeCode);
    await page.select('#examyear', cleanYear);
    try {
        await page.select('select[name="cexamyear"]', cleanYear);
    } catch (e) {}
    await page.type('#serial', cleanSerial, { delay: 15 });
    await page.type('#pin', cleanPin, { delay: 15 });

    console.log('[WAEC Direct] Submitting form...');
    await page.click('input[name="Submit"]');
    await new Promise(r => setTimeout(r, 1000));

    // Handle jQuery confirmation modal
    const hasDialog = await page.evaluate(() => {
        const d = document.getElementById('dialog-confirm');
        return d && window.$ && $(d).dialog && $(d).dialog('isOpen');
    });

    if (hasDialog) {
        console.log('[WAEC Direct] Dialog open. Clicking Agree button...');
        await page.evaluate(() => {
            const btns = Array.from(document.querySelectorAll('.ui-dialog-buttonpane button'));
            const agreeBtn = btns.find(b => b.innerText && b.innerText.trim().toLowerCase().includes('agree'));
            if (agreeBtn) {
                agreeBtn.click();
            } else if (btns.length > 0) {
                btns[0].click();
            }
        });
    }

    let activePage = page;
    try {
        const popup = await popupPromise;
        if (popup) {
            console.log('[WAEC Direct] Switched to popup window:', popup.url());
            activePage = popup;
        }
    } catch (e) {}

    const currentUrl = activePage.url();
    if (currentUrl === 'about:blank' || currentUrl.endsWith('.org/') || currentUrl.endsWith('index.htm')) {
        try {
            await activePage.waitForNavigation({ waitUntil: 'networkidle2', timeout: 15000 });
        } catch (e) {}
    } else {
        try {
            await activePage.waitForFunction(() => document.body && document.body.innerText.trim().length > 20, { timeout: 10000 });
        } catch (e) {}
    }

    await new Promise(r => setTimeout(r, 1500));

    const pageUrl = activePage.url();
    const pageTitle = await activePage.title();
    console.log(`[WAEC Direct] Final URL: ${pageUrl} | Title: ${pageTitle}`);

    const extracted = await activePage.evaluate(() => {
        const body = document.body ? document.body.innerText : '';
        const html = document.documentElement ? document.documentElement.outerHTML : '';
        return { body, html };
    });

    await activePage.screenshot({
        path: screenshotFilePath,
        fullPage: true
    });
    console.log(`[WAEC Direct] Saved official screenshot to: ${screenshotFilePath}`);

    // PDF generation for the result slip
    const pdfFileName = `WAEC_Result_${cleanIndex}_${cleanYear}.pdf`;
    const pdfFilePath = path.resolve(resultsDir, pdfFileName);
    try {
        await activePage.pdf({
            path: pdfFilePath,
            format: 'A4',
            printBackground: true,
            margin: {
                top: '10mm',
                right: '10mm',
                bottom: '10mm',
                left: '10mm'
            }
        });
        console.log(`[WAEC Direct] Generated official PDF document: ${pdfFilePath}`);
    } catch (pdfErr) {
        console.warn(`[WAEC Direct] PDF export notice (fallback to screenshot):`, pdfErr.message);
    }

    const bodyText = extracted.body;

    const isErrorPage = pageUrl.includes('error.asp') ||
        bodyText.includes('Invalid Card') ||
        bodyText.includes('Card Limit') ||
        bodyText.includes('Unexpected Error') ||
        bodyText.includes('Access Violation') ||
        bodyText.includes('ERROR!!!');

    if (isErrorPage) {
        let errorTitle = 'WAEC Portal Notice';
        let errorDetail = '';

        const titleMatch = bodyText.match(/(Invalid Card[^\n\r]*|Card Limit[^\n\r]*|Unexpected Error[^\n\r]*|Access Violation[^\n\r]*)/i);
        if (titleMatch) {
            errorTitle = titleMatch[1].trim();
        }

        const lines = bodyText.split('\n').map(l => l.trim()).filter(Boolean);
        const noticeIdx = lines.findIndex(l => /invalid card|card limit|unexpected error|error!/i.test(l));
        if (noticeIdx !== -1 && lines[noticeIdx + 1]) {
            errorDetail = lines.slice(noticeIdx + 1, noticeIdx + 4).join(' ').replace(/Close this window/gi, '').trim();
        }
        if (!errorDetail) {
            errorDetail = bodyText.substring(0, 300).replace(/\s+/g, ' ').trim();
        }

        const errorReply = `❌ *WAEC PORTAL OFFICIAL NOTICE*\n` +
            `━━━━━━━━━━━━━━━━━━━━━\n` +
            `⚠️ *Portal:* https://ghana.waecdirect.org/\n` +
            `⚠️ *${errorTitle}*\n` +
            `${errorDetail}\n\n` +
            `📋 *Submitted Details*:\n` +
            `• Exam: *${examTypeName} ${cleanYear}*\n` +
            `• Index Number: \`${cleanIndex}\`\n` +
            `• Voucher Serial: \`${cleanSerial}\`\n` +
            `━━━━━━━━━━━━━━━━━━━━━\n` +
            `💳 *Need a fresh WAEC Checker Card?*\n` +
            `Buy instantly with instant card PIN & Serial at:\n` +
            `👉 https://apexprime.club/digital_store\n` +
            `👉 Or reply *2* to buy directly`;

        return {
            success: false,
            is_error_page: true,
            portal: 'https://ghana.waecdirect.org/',
            error_title: errorTitle,
            error_message: errorDetail,
            image_path: screenshotFilePath,
            image_file: screenshotFilePath,
            reply: errorReply
        };
    }

    const structuredData = await activePage.evaluate(() => {
        const data = {
            candidateName: '',
            indexNumber: '',
            examType: '',
            schoolName: '',
            cardUse: '',
            subjects: []
        };

        const rows = Array.from(document.querySelectorAll('tr'));
        for (const row of rows) {
            const cells = Array.from(row.querySelectorAll('td, th')).map(c => c.innerText.trim());
            if (cells.length >= 2) {
                const label = cells[0].toLowerCase();
                const val = cells[1];
                if (label.includes('candidate name')) data.candidateName = val;
                else if (label.includes('index number')) data.indexNumber = val;
                else if (label.includes('type of examination')) data.examType = val;
                else if (label.includes('examination centre') || label.includes('school')) data.schoolName = val;
                else if (label.includes('card use')) data.cardUse = val;
            }
            if (cells.length >= 3) {
                const sub = cells[0];
                const grd = cells[1];
                const rmk = cells[2];
                // Subject row check
                if (/^[A-Z\s\/\(\)\-]+$/i.test(sub) && /^[A-Z][0-9]|[1-9]$/i.test(grd) && !sub.toLowerCase().includes('index') && !sub.toLowerCase().includes('candidate') && !sub.toLowerCase().includes('results')) {
                    data.subjects.push({ subject: sub, grade: grd, remark: rmk });
                }
            }
        }
        return data;
    });

    // Parse Valid Result Slip
    let candidateName = structuredData.candidateName;
    let schoolName = structuredData.schoolName;
    let cardUse = structuredData.cardUse || '1 of 3';

    if (!candidateName) {
        const nameMatch = bodyText.match(/Candidate(?:\'s)?\s*Name[:\s]*([A-Z\s\.\-]{3,60})/i);
        if (nameMatch) {
            candidateName = nameMatch[1].replace(/Index|Number|School|Exam/gi, '').trim();
        }
    }
    if (!candidateName) candidateName = `CANDIDATE (${cleanIndex})`;

    if (!schoolName) {
        const schoolMatch = bodyText.match(/(?:School|Centre|Center)[:\s]*([A-Z0-9\s\.\-]{3,80})/i);
        if (schoolMatch) schoolName = schoolMatch[1].trim();
    }

    let subjects = structuredData.subjects;
    if (!subjects || subjects.length === 0) {
        const subjectRegex = /([A-Z\s\/\(\)]+?)\s+([A-Z][0-9]|[1-9])\s+([A-Z\s]+)/gi;
        subjects = [];
        let sm;
        while ((sm = subjectRegex.exec(bodyText)) !== null) {
            const sub = sm[1].trim();
            const grd = sm[2].trim();
            const rmk = sm[3].trim();
            if (sub.length >= 3 && !['EXAMINATION', 'INDEX', 'CANDIDATE', 'DATE OF BIRTH', 'RESULTS'].includes(sub.toUpperCase())) {
                subjects.push({ subject: sub, grade: grd, remark: rmk });
            }
        }
    }

    let aggregateStr = '';
    if (subjects.length >= 6) {
        const scored = [];
        for (const s of subjects) {
            const pts = getGradePoints(s.grade);
            if (pts !== null) scored.push({ subject: s.subject, pts });
        }
        if (scored.length >= 6) {
            scored.sort((a, b) => a.pts - b.pts);
            const best6 = scored.slice(0, 6);
            const tot = best6.reduce((acc, c) => acc + c.pts, 0);
            aggregateStr = `\n🏆 *Calculated Aggregate*: *${String(tot).padStart(2, '0')}* (Best 6 Subjects)\n`;
        }
    }

    let subjectList = '';
    for (const s of subjects) {
        subjectList += `• ${s.subject}: *${s.grade}* (${s.remark})\n`;
    }

    const successReply = `🎓 *WEST AFRICAN EXAMINATIONS COUNCIL* 🎓\n` +
        `*OFFICIAL PROVISIONAL RESULTS SLIP*\n` +
        `━━━━━━━━━━━━━━━━━━━━━\n` +
        `👤 *Candidate Name*: *${candidateName}*\n` +
        `🔢 *Index Number*: \`${cleanIndex}\`\n` +
        `🏫 *Examination*: *${examTypeName} ${cleanYear}*\n` +
        (schoolName ? `📍 *School / Centre*: *${schoolName}*\n` : '') +
        `━━━━━━━━━━━━━━━━━━━━━\n` +
        `📊 *SUBJECT RESULTS*:\n` +
        (subjectList || 'See the attached official result slip image.\n') +
        `━━━━━━━━━━━━━━━━━━━━━` +
        `${aggregateStr}` +
        `💳 *Card Usage*: *${cardUse}*\n` +
        `✅ *Captured live from WAEC Direct Ghana Portal (https://ghana.waecdirect.org/)*\n` +
        `━━━━━━━━━━━━━━━━━━━━━\n` +
        `_Powered by Apex Prime Tech (0553381853)_`;

        // Generate high-resolution PDF document from the official result slip screenshot
        if (convertImageToResultPdf) {
            try {
                await convertImageToResultPdf(screenshotFilePath, pdfFilePath, candidateName, cleanIndex, cleanYear);
                console.log(`[WAEC Direct] Formatted official result slip PDF generated: ${pdfFilePath}`);
            } catch (e) {
                console.warn(`[WAEC Direct] Error formatting PDF slip:`, e.message);
            }
        }

        return {
            success: true,
            candidate_name: candidateName,
            index_number: cleanIndex,
            exam_type: examTypeName,
            exam_year: cleanYear,
            school_name: schoolName,
            subjects,
            image_path: screenshotFilePath,
            image_file: screenshotFilePath,
            pdf_file: fs.existsSync(pdfFilePath) ? pdfFilePath : null,
            pdf_name: pdfFileName,
            reply: successReply
        };
}

/**
 * Universal Entry Point
 */
async function checkWaecWithPuppeteer(examTypeInput, typeCodeInput, indexNumber, examYear, serial, pin) {
    const cleanIndex = String(indexNumber || '').replace(/\D/g, '').trim();
    const cleanYear = String(examYear || '').replace(/\D/g, '').trim();
    const cleanSerial = String(serial || '').toUpperCase().trim();
    const cleanPin = String(pin || '').replace(/\D/g, '').trim();

    let typeCode = typeCodeInput || '01';
    let examTypeName = examTypeInput || 'W.A.S.S.C.E. (School)';

    const normType = (examTypeName + ' ' + (examTypeInput || '')).toUpperCase().replace(/\./g, '');
    const isBece = normType.includes('BECE');
    const isPrivate = normType.includes('PRIV') || normType.includes('NOV');

    if (isBece) {
        examTypeName = isPrivate ? 'B.E.C.E. (Private)' : 'B.E.C.E.';
        typeCode = isPrivate ? '09' : '07';
    } else {
        examTypeName = isPrivate ? 'W.A.S.S.C.E. (Private)' : 'W.A.S.S.C.E. (School)';
        typeCode = isPrivate ? '08' : '01';
    }

    if (!puppeteer) {
        return {
            success: false,
            message: 'Puppeteer automation engine is not installed on this server.',
            error_code: 'NO_PUPPETEER'
        };
    }

    const chromePath = findChromePath();
    if (!chromePath) {
        return {
            success: false,
            message: 'Chrome browser executable was not found on this system.',
            error_code: 'NO_CHROME'
        };
    }

    const resultsDir = path.resolve(__dirname, '../uploads/results');
    if (!fs.existsSync(resultsDir)) {
        try { fs.mkdirSync(resultsDir, { recursive: true }); } catch (e) {}
    }

    const prefix = isBece ? 'BECE' : 'WAEC';
    const timestamp = Date.now();
    const screenshotFileName = `${prefix}_${cleanIndex}_${cleanYear}_${timestamp}.png`;
    const screenshotFilePath = path.join(resultsDir, screenshotFileName);

    let browser = null;
    try {
        browser = await puppeteer.launch({
            executablePath: chromePath,
            headless: "new",
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
                '--disable-gpu',
                '--window-size=1280,1000'
            ]
        });

        let result;
        if (isBece) {
            // Check BECE on https://eresults.waecgh.org/
            result = await checkBeceEresults(browser, cleanIndex, cleanYear, isPrivate, cleanSerial, cleanPin, screenshotFilePath, resultsDir);
        } else {
            // Check WASSCE on https://ghana.waecdirect.org/
            result = await checkWassceDirect(browser, cleanIndex, cleanYear, typeCode, examTypeName, cleanSerial, cleanPin, screenshotFilePath, resultsDir);
        }

        await browser.close();
        return result;

    } catch (err) {
        console.error('[WAEC/eResults Engine Error]:', err);
        if (browser) {
            try { await browser.close(); } catch (e) {}
        }
        return {
            success: false,
            message: `WAEC automation error: ${err.message}`,
            error_code: 'AUTOMATION_FAILED'
        };
    }
}

// CLI Support: node waec_checker.js <examType> <typeCode> <index> <year> <serial> <pin>
if (require.main === module) {
    const args = process.argv.slice(2);
    const examType = args[0] || 'BECE';
    const typeCode = args[1] || '07';
    const indexNumber = args[2] || '1010102002';
    const examYear = args[3] || '2024';
    const serial = args[4] || '180000000001';
    const pin = args[5] || '686064817245';

    checkWaecWithPuppeteer(examType, typeCode, indexNumber, examYear, serial, pin)
        .then(res => {
            console.log('\n--- RESULT JSON OUTPUT ---');
            console.log(JSON.stringify(res, null, 2));
            process.exit(0);
        })
        .catch(err => {
            console.error(err);
            process.exit(1);
        });
}

module.exports = {
    checkWaecWithPuppeteer,
    findChromePath
};
