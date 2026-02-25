; Inno Setup Script Template - متجر قطع الغيار
; عدّل المسارات واسم ملف الـ EXE حسب مشروعك (مثلاً الناتج من PHPDesktop)

#define AppName "متجر قطع الغيار"
#define AppVersion "1.0.0"
#define AppPublisher "Your Company"
#define AppExeName "phpdesktop.exe"  ; أو اسم البرنامج بعد التعديل

[Setup]
AppId={{D2E4C10A-7B5C-4E50-8B26-1D8B0D8E0A10}
AppName={#AppName}
AppVersion={#AppVersion}
AppPublisher={#AppPublisher}
DefaultDirName={pf}\{#AppName}
DefaultGroupName={#AppName}
DisableDirPage=no
DisableProgramGroupPage=no
OutputBaseFilename=PartsStoreSetup
Compression=lzma
SolidCompression=yes
WizardStyle=modern

[Languages]
Name: "arabic"; MessagesFile: "compiler:Languages\Arabic.isl"

[Files]
; ضع هنا مسار ملفات النسخة النهائية (مجلد الـ dist الناتج من PHPDesktop)
; مثال: Source: "dist\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "dist\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs

[Icons]
Name: "{group}\{#AppName}"; Filename: "{app}\{#AppExeName}"
Name: "{group}\إلغاء تثبيت {#AppName}"; Filename: "{uninstallexe}"

[Run]
Filename: "{app}\{#AppExeName}"; Description: "تشغيل {#AppName}"; Flags: nowait postinstall skipifsilent
