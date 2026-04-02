import re

filepath = "/var/www/staging/app/code/Ecom360/Analytics/view/frontend/templates/aisearch.phtml"

with open(filepath, "r") as f:
    content = f.read()

changes = 0

# 1. Add Enter key handler after the "var activeFacets = {};" line
marker1 = "var activeFacets = {};"
if marker1 in content:
    replacement1 = marker1 + """

    // Enter key -> redirect to full search results page
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            var q = input.value.trim();
            if (q.length > 0) {
                window.location.href = '/ecom360/search/results?q=' + encodeURIComponent(q);
            }
        }
    });"""
    content = content.replace(marker1, replacement1, 1)
    changes += 1
    print("1. Added Enter key handler")

# 2. Add "View All Results" link inside renderResults
# Locate the pattern where we close the forEach click handler block
marker2 = "if (url && url !== '#') window.location.href = url;"
idx = content.find(marker2)
if idx > 0:
    # Find the next occurrence of "});\n    }" after this marker
    after = content[idx:]
    # Find the "});\n" that closes the forEach
    close_idx = after.find("});\n        });\n    }")
    if close_idx > 0:
        insert_point = idx + close_idx + len("});\n        });\n")
        insert_code = """        // Add "View All Results" link at bottom
        var currentQ = input.value.trim();
        if (currentQ) {
            var viewAllDiv = document.createElement('div');
            viewAllDiv.style.textAlign = 'center';
            viewAllDiv.style.padding = '16px 0 8px';
            viewAllDiv.style.borderTop = '1px solid #f0f0f0';
            viewAllDiv.style.marginTop = '8px';
            var viewAllLink = document.createElement('a');
            viewAllLink.href = '/ecom360/search/results?q=' + encodeURIComponent(currentQ);
            viewAllLink.style.color = '#667eea';
            viewAllLink.style.fontWeight = '600';
            viewAllLink.style.fontSize = '14px';
            viewAllLink.style.textDecoration = 'none';
            viewAllLink.textContent = 'View All Results \\u2192';
            viewAllDiv.appendChild(viewAllLink);
            resultsDiv.appendChild(viewAllDiv);
        }
"""
        content = content[:insert_point] + insert_code + content[insert_point:]
        changes += 1
        print("2. Added View All Results link")
    else:
        print("2. SKIPPED - could not find forEach close pattern")
else:
    print("2. SKIPPED - marker not found")

with open(filepath, "w") as f:
    f.write(content)

print(f"Done. {changes} changes applied.")
