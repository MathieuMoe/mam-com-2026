<?php
// Data shared by the 3 languages: contact links, skills (with keys used by recruiter links), stack.
return [
    'name' => 'Mathieu Moreau',
    'email' => 'mathieu.moreau80@gmail.com',
    'phone' => '+33 6 70 73 09 91',
    'github' => 'https://github.com/MathieuMoe',
    'linkedin' => 'https://www.linkedin.com/in/mathieu-moreau-045b26114/',
    // key => [label, level 1-10]
    'skills' => [
        'dev' => [
            'csharp' => ['C# / .NET', 8], 'aspnet' => ['ASP.NET Core · MVC · WebForms', 8], 'sql' => ['SQL Server / SQL', 7],
            'html' => ['HTML5 / CSS3', 9], 'js' => ['JavaScript / jQuery', 6], 'wordpress' => ['WordPress', 9],
            'php' => ['PHP / Symfony', 5], 'git' => ['Git / GitHub', 7], 'docker' => ['Docker', 6],
        ],
        'design' => [
            'figma' => ['Figma / Adobe XD', 8], 'illustrator' => ['Illustrator', 9], 'lightroom' => ['Lightroom', 8],
            'photoshop' => ['Photoshop', 7], 'indesign' => ['InDesign', 6], 'aftereffects' => ['After Effects', 5], 'premiere' => ['Premiere Pro', 5],
        ],
        'ai' => [
            'claude' => ['Claude / Claude Code', 8], 'llm' => ['LLM APIs (Claude, OpenAI, Gemini, Ollama)', 7],
            'prompt' => ['Prompt engineering · structured output', 8], 'tailwind' => ['Tailwind CSS · Preline', 8], 'playwright' => ['Playwright · xUnit', 6],
        ],
    ],
    // marquee band
    'marquee' => ['C#', '.NET 10', 'ASP.NET Core', 'SQL Server', 'Figma', 'UI/UX', 'Claude', 'OpenAI', 'Gemini', 'Docker', 'Git', 'WordPress', 'Illustrator', 'Tailwind', 'JavaScript', 'PHP'],
];
