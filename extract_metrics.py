import sys
import json
import subprocess
import shlex
import ast
from pathlib import Path
from typing import List, Dict

def run_cmd(cmd):
    try:
        proc = subprocess.run(shlex.split(cmd), capture_output=True, text=True, timeout=60)
        return proc.returncode, proc.stdout, proc.stderr
    except subprocess.TimeoutExpired:
        return -1, "", "TIMEOUT"
    except Exception as e:
        return -2, "", str(e)


class DangerousPatternDetector(ast.NodeVisitor):
    def __init__(self):
        self.issues = []

    def add_issue(self, issue_type: str, line: int, message: str, severity: int):
        self.issues.append({
            "type": issue_type,
            "line": line,
            "message": message,
            "severity": severity
        })

    def visit_While(self, node):
        if isinstance(node.test, ast.Constant) and node.test.value is True:
            has_break = any(isinstance(n, ast.Break) for n in ast.walk(node))
            if not has_break:
                self.add_issue("INFINITE_LOOP", node.lineno, "Infinite loop detected", 95)
        self.generic_visit(node)

    def visit_BinOp(self, node):
        if isinstance(node.op, (ast.Div, ast.FloorDiv, ast.Mod)):
            if isinstance(node.right, ast.Constant) and node.right.value == 0:
                self.add_issue("ZERO_DIVISION", node.lineno, "Division by zero detected", 90)
        self.generic_visit(node)

    def visit_Call(self, node):
        dangerous_calls = {
            'eval': ("EVAL_USAGE", 100),
            'exec': ("EXEC_USAGE", 100),
            '__import__': ("DYNAMIC_IMPORT", 80),
            'compile': ("COMPILE_USAGE", 85),
        }

        if isinstance(node.func, ast.Name):
            if node.func.id in dangerous_calls:
                issue, sev = dangerous_calls[node.func.id]
                self.add_issue(issue, node.lineno, node.func.id, sev)

        elif isinstance(node.func, ast.Attribute):
            if isinstance(node.func.value, ast.Name):
                mod = node.func.value.id
                attr = node.func.attr
                if mod == 'os' and attr in ['remove', 'unlink', 'rmdir']:
                    self.add_issue("FILE_DELETION", node.lineno, f"os.{attr}", 95)
                elif mod == 'os' and attr in ['system', 'popen', 'exec']:
                    self.add_issue("SHELL_EXECUTION", node.lineno, f"os.{attr}", 100)
                elif mod == 'subprocess':
                    self.add_issue("SUBPROCESS_CALL", node.lineno, f"subprocess.{attr}", 75)
                elif mod == 'shutil' and attr == 'rmtree':
                    self.add_issue("DIRECTORY_DELETION", node.lineno, "shutil.rmtree", 95)

        self.generic_visit(node)

    def visit_Subscript(self, node):
        if isinstance(node.slice, ast.BinOp) and isinstance(node.slice.op, ast.Add):
            if isinstance(node.slice.right, ast.Constant) and node.slice.right.value == 1:
                self.add_issue("POTENTIAL_INDEX_ERROR", node.lineno, "Potential IndexError", 60)
        self.generic_visit(node)

    def visit_Import(self, node):
        dangerous = {'pickle': 65, 'marshal': 70, 'shelve': 65}
        for alias in node.names:
            if alias.name in dangerous:
                self.add_issue("DANGEROUS_IMPORT", node.lineno, alias.name, dangerous[alias.name])
        self.generic_visit(node)

    def visit_ImportFrom(self, node):
        if node.module == 'os':
            for n in node.names:
                if n.name in ['remove', 'system', 'unlink']:
                    self.add_issue("DANGEROUS_IMPORT", node.lineno, "os import", 80)
        self.generic_visit(node)


def calculate_deterministic_severity(issues: List[Dict]) -> int:
    if not issues:
        return 0
    max_sev = max(i["severity"] for i in issues)
    critical = sum(1 for i in issues if i["severity"] >= 80)
    if critical > 1:
        max_sev = min(100, max_sev + (critical - 1) * 5)
    return max_sev


def analyze_ast_patterns(file_path: Path):
    try:
        source = file_path.read_text(encoding="utf-8")
        tree = ast.parse(source)
        detector = DangerousPatternDetector()
        detector.visit(tree)
        sev = calculate_deterministic_severity(detector.issues)
        return detector.issues, sev
    except SyntaxError as e:
        return [{
            "type": "SYNTAX_ERROR",
            "line": e.lineno or 0,
            "message": e.msg,
            "severity": 50
        }], 50
    except Exception as e:
        return [{
            "type": "ANALYSIS_ERROR",
            "line": 0,
            "message": str(e),
            "severity": 0
        }], 0


def analyze_file(file_path):
    metrics = {
        "radon_total_complexity": 0,
        "radon_num_items": 0,
        "pylint_msgs_count": 0,
        "pylint_rc": 0,
        "bandit_issues_count": 0,
        "bandit_rc": 0,
        "critical_issues": [],
        "deterministic_severity": 0,
        "deterministic_clean": False
    }

    rc, out, _ = run_cmd(f'{sys.executable} -m radon cc -s -j "{file_path}"')
    if rc == 0 and out:
        try:
            parsed = json.loads(out)
            for items in parsed.values():
                for it in items:
                    metrics["radon_total_complexity"] += int(it.get("complexity", 0))
                    metrics["radon_num_items"] += 1
        except:
            pass

    rc, out, _ = run_cmd(f'{sys.executable} -m pylint --output-format=json "{file_path}"')
    metrics["pylint_rc"] = rc
    if out:
        try:
            parsed = json.loads(out)
            metrics["pylint_msgs_count"] = len(parsed) if isinstance(parsed, list) else 0
        except:
            pass

    rc, out, _ = run_cmd(f'{sys.executable} -m bandit -r "{file_path}" -f json')
    metrics["bandit_rc"] = rc
    if out:
        try:
            parsed = json.loads(out)
            metrics["bandit_issues_count"] = len(parsed.get("results", []))
        except:
            pass

    issues, severity = analyze_ast_patterns(file_path)
    metrics["critical_issues"] = issues
    metrics["deterministic_severity"] = severity
    metrics["deterministic_clean"] = severity == 0 and len(issues) == 0

    return metrics


if __name__ == "__main__":
    if len(sys.argv) < 2:
        print(json.dumps({"error": "No file provided"}))
        sys.exit(1)

    target = Path(sys.argv[1])
    if not target.exists():
        print(json.dumps({"error": "File not found"}))
        sys.exit(1)

    result = analyze_file(target)
    print(json.dumps(result))
