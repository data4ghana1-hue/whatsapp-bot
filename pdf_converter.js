const puppeteer = require('puppeteer-core');
const { findChromePath } = require('./waec_checker.js');
const fs = require('fs');
const path = require('path');

async function convertImageToResultPdf(imagePath, outputPath, candidateName, indexNumber, examYear) {
    if (!fs.existsSync(imagePath)) {
        throw new Error(`Image not found: ${imagePath}`);
    }

    const chrome = findChromePath();
    if (!chrome) {
        throw new Error('Chrome browser not found.');
    }

    const browser = await puppeteer.launch({
        executablePath: chrome,
        headless: 'new',
        args: ['--no-sandbox', '--disable-setuid-sandbox']
    });

    try {
        const page = await browser.newPage();
        const imgBase64 = fs.readFileSync(imagePath).toString('base64');
        const candStr = candidateName ? candidateName : 'Candidate';
        const indexStr = indexNumber ? indexNumber : '';
        const yearStr = examYear ? examYear : '';

        const html = `<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page {
            size: A4 portrait;
            margin: 8mm;
        }
        * {
            box-sizing: border-box;
        }
        body {
            margin: 0;
            padding: 0;
            font-family: Arial, Helvetica, sans-serif;
            background-color: #ffffff;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
        }
        .header {
            width: 100%;
            max-width: 780px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 2.5px solid #006039;
            padding-bottom: 6px;
            margin-bottom: 12px;
        }
        .header-title {
            color: #006039;
            font-size: 15px;
            font-weight: bold;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }
        .header-sub {
            font-size: 10px;
            color: #555;
            margin-top: 2px;
        }
        .header-right {
            text-align: right;
            font-size: 10px;
            color: #444;
            line-height: 1.3;
        }
        .slip-container {
            width: 100%;
            max-width: 780px;
            text-align: center;
        }
        .slip-img {
            max-width: 100%;
            max-height: 250mm;
            height: auto;
            border: 1px solid #d0d0d0;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08);
            border-radius: 4px;
        }
        .footer {
            width: 100%;
            max-width: 780px;
            margin-top: 10px;
            padding-top: 6px;
            border-top: 1px dashed #bbb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 9px;
            color: #777;
        }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <div class="header-title">The West African Examinations Council</div>
            <div class="header-sub">Official Provisional Results Slip & Printout Document</div>
        </div>
        <div class="header-right">
            <div><strong>Candidate:</strong> ${candStr}</div>
            <div><strong>Index No:</strong> ${indexStr} (${yearStr})</div>
        </div>
    </div>
    <div class="slip-container">
        <img class="slip-img" src="data:image/png;base64,${imgBase64}" />
    </div>
    <div class="footer">
        <div>✓ Official results verification printout captured live from WAEC Direct Ghana.</div>
        <div>Apex Prime Portal Services • Support: 0553381853</div>
    </div>
</body>
</html>`;

        await page.setContent(html, { waitUntil: 'load' });
        await page.pdf({
            path: outputPath,
            format: 'A4',
            printBackground: true,
            margin: { top: '8mm', bottom: '8mm', left: '8mm', right: '8mm' }
        });

        console.log(`[PDF Generator] Successfully created PDF: ${outputPath} (${fs.statSync(outputPath).size} bytes)`);
        return outputPath;
    } finally {
        await browser.close();
    }
}

module.exports = {
    convertImageToResultPdf
};

if (require.main === module) {
    const testImg = 'C:/console/uploads/results/WAEC_0021502691_2026_1789258770962.png';
    const testOut = 'C:/console/uploads/results/WAEC_Result_0021502691_2026.pdf';
    convertImageToResultPdf(testImg, testOut, 'OFFEI EMMANUEL OPATA', '0021502691', '2026')
        .then(() => console.log('Test completed.'))
        .catch(console.error);
}
