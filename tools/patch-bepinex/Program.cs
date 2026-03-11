// Patches BepInEx.Preloader.dll to wrap RuntimeFix Apply() methods in try-catch.
// This prevents NullReferenceException in MonoMod.RuntimeDetour.DetourHelper.GetIdentifiable
// that crashes BepInEx initialization on macOS arm64 (M-series Macs).
//
// Wraps Apply() in: ConsoleSetOutFix, XTermFix, and any other RuntimeFixes class.
//
// Usage: dotnet run -- <path-to-BepInEx.Preloader.dll>

using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using AsmResolver.DotNet;
using AsmResolver.DotNet.Code.Cil;
using AsmResolver.PE.DotNet.Cil;

if (args.Length < 1)
{
    Console.Error.WriteLine("Usage: patch-bepinex <path-to-BepInEx.Preloader.dll>");
    return 1;
}

string dllPath = args[0];
if (!File.Exists(dllPath))
{
    Console.Error.WriteLine($"File not found: {dllPath}");
    return 1;
}

string backupPath = dllPath + ".orig";
if (!File.Exists(backupPath))
{
    File.Copy(dllPath, backupPath);
    Console.WriteLine($"Backup: {backupPath}");
}

var module = ModuleDefinition.FromFile(dllPath);

// Find all types in BepInEx.Preloader.RuntimeFixes namespace
var allTypes = module.TopLevelTypes
    .Concat(module.TopLevelTypes.SelectMany(t => t.NestedTypes))
    .ToList();

var runtimeFixTypes = allTypes
    .Where(t => t.Namespace == "BepInEx.Preloader.RuntimeFixes")
    .ToList();

Console.WriteLine($"Found {runtimeFixTypes.Count} RuntimeFix type(s): {string.Join(", ", runtimeFixTypes.Select(t => t.Name))}");

var corlibScope = module.CorLibTypeFactory.CorLibScope;
var exceptionTypeRef = new TypeReference(module, corlibScope, "System", "Exception");

int patchCount = 0;

foreach (var fixType in runtimeFixTypes)
{
    var applyMethod = fixType.Methods.FirstOrDefault(m => m.Name == "Apply");
    if (applyMethod == null) continue;

    var body = applyMethod.CilMethodBody;
    if (body == null) continue;

    if (body.ExceptionHandlers.Count > 0)
    {
        Console.WriteLine($"  {fixType.Name}.Apply() — already patched, skipping");
        continue;
    }

    Console.WriteLine($"  Patching {fixType.Name}.Apply()...");

    var instructions = body.Instructions;
    var retInstr = instructions.LastOrDefault(i => i.OpCode == CilOpCodes.Ret);
    if (retInstr == null)
    {
        Console.WriteLine($"  {fixType.Name}.Apply() has no ret — skipping");
        continue;
    }

    // catch block: pop exception + ret
    var catchPop = new CilInstruction(CilOpCodes.Pop);
    var catchRet = new CilInstruction(CilOpCodes.Ret);

    // Replace try-body ret with leave_s -> catchRet
    retInstr.ReplaceWith(CilOpCodes.Leave_S, catchRet.CreateLabel());

    instructions.Add(catchPop);
    instructions.Add(catchRet);

    body.ExceptionHandlers.Add(new CilExceptionHandler
    {
        HandlerType  = CilExceptionHandlerType.Exception,
        TryStart     = instructions[0].CreateLabel(),
        TryEnd       = catchPop.CreateLabel(),
        HandlerStart = catchPop.CreateLabel(),
        HandlerEnd   = catchRet.CreateLabel(),
        ExceptionType = exceptionTypeRef,
    });

    patchCount++;
}

if (patchCount == 0)
{
    Console.WriteLine("Nothing to patch.");
    return 0;
}

string tempPath = dllPath + ".patched";
module.Write(tempPath);
File.Move(tempPath, dllPath, overwrite: true);

Console.WriteLine($"Done. Patched {patchCount} RuntimeFix method(s).");
Console.WriteLine("BepInEx mods will load on macOS arm64 even if Harmony IL patching fails.");
return 0;
