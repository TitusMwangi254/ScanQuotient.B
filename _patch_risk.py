from pathlib import Path

def main():
    p = Path("Private/Python_Module/scanner_api.py")
    text = p.read_text(encoding="utf-8")
    old = """            risk_score = min(round((raw_score / SCORE_CEILING) * 100), 100)
            risk_level = ('Critical' if risk_score >= 60 else
                          'High'     if risk_score >= 30 else
                          'Medium'   if risk_score >= 12 else
                          'Low'      if risk_score >  0  else 'Secure')

            scan_duration = round(time.time() - start_time, 2)"""
    new = """            risk_score = min(round((raw_score / SCORE_CEILING) * 100), 100)
            risk_level = ('Critical' if risk_score >= 60 else
                          'High'     if risk_score >= 30 else
                          'Medium'   if risk_score >= 12 else
                          'Low'      if risk_score >  0  else 'Secure')

            contributions = {
                'critical': severity_counts['critical'] * 10,
                'high':     severity_counts['high'] * 5,
                'medium':   severity_counts['medium'] * 2,
                'low':      severity_counts['low'] * 1,
            }
            risk_score_detail = {
                'raw_points': int(raw_score),
                'raw_points_cap': SCORE_CEILING,
                'risk_score_0_100': int(risk_score),
                'formula_short': (
                    'Issue points = Critical×10 + High×5 + Medium×2 + Low×1; '
                    'score is that total scaled to 0–100 (capped).'
                ),
                'weights': {'critical': 10, 'high': 5, 'medium': 2, 'low': 1},
                'contributions': contributions,
                'excluded_from_score': ['info', 'secure'],
                'note': (
                    'Informational and "secure" findings are counted separately '
                    'and are not part of this numeric score.'
                ),
            }

            scan_duration = round(time.time() - start_time, 2)"""
    assert old in text, "anchor1"
    text = text.replace(old, new, 1)
    old2 = """                summary={
                    'total_vulnerabilities': len(all_vulnerabilities),
                    'severity_breakdown':    severity_counts,
                    'risk_score':            risk_score,
                    'risk_level':            risk_level,
                    'scan_status':           'completed',
                    'user_friendly':         friendly_summary
                },"""
    new2 = """                summary={
                    'total_vulnerabilities': len(all_vulnerabilities),
                    'severity_breakdown':    severity_counts,
                    'risk_score':            risk_score,
                    'risk_level':            risk_level,
                    'risk_score_detail':     risk_score_detail,
                    'scan_status':           'completed',
                    'user_friendly':         friendly_summary
                },"""
    assert old2 in text, "anchor2"
    text = text.replace(old2, new2, 1)
    p.write_text(text, encoding="utf-8")
    print("ok")

main()
