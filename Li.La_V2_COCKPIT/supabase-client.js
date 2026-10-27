  const LILA_SUPABASE_URL = 'https://ldnibtgpbygefgucgfaf.supabase.co';
  const LILA_SUPABASE_ANON_KEY = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImxkbmlidGdwYnlnZWZndWNnZmFmIiwicm9sZSI6ImFub24iLCJpYXQiOjE3ODQ0NDI4NjUsImV4cCI6MjEwMDAxODg2NX0._wMkfaKsU3bKRzygWOEvJX8BDfvAbf_XQuHbtmF7Isg';
  const LILA_MAGIC_LINK_REDIRECT = 'https://app.li-la.fr/';
  const lilaSupabase = window.supabase.createClient(LILA_SUPABASE_URL, LILA_SUPABASE_ANON_KEY, {
    auth: { persistSession: true, autoRefreshToken: true, detectSessionInUrl: true }
  });
