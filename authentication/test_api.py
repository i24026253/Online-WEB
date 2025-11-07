import requests

# Test whether the PHP API can be called from Python.
def test_api():
    try:
        response = requests.get('http://localhost/php_module/analytics.php?action=percentage&student_id=1', timeout=5)
        print("✅ API call successful!")
        print("Return data：", response.json())
    except Exception as e:
        print("❌ API call failed：", e)

if __name__ == "__main__":
    test_api()