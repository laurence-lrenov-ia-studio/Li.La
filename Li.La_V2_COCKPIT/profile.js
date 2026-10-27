const LILA_USER_PROFILE_KEY = 'lila_user_profile';
function lilaDefaultUserProfile(){ return {}; }
  function lilaCleanUserProfileValue(value){
    return String(value==null?'':value).trim();
  }
  function lilaNormalizeUserProfile(source){
    source=source||{};
    return {
      firstName:lilaCleanUserProfileValue(source.firstName),
      lastName:lilaCleanUserProfileValue(source.lastName),
      email:lilaCleanUserProfileValue(source.email),
      phone:lilaCleanUserProfileValue(source.phone),
      job:lilaCleanUserProfileValue(source.job),
      company:lilaCleanUserProfileValue(source.company),
      location:lilaCleanUserProfileValue(source.location),
      signature:lilaCleanUserProfileValue(source.signature)
    };
  }
  function lilaReadUserProfile(){
    try{
      var raw=localStorage.getItem(LILA_USER_PROFILE_KEY);
      if(raw){
        var stored=JSON.parse(raw);
        if(stored&&typeof stored==='object')return lilaNormalizeUserProfile(stored);
      }
    }catch(e){}
    return lilaNormalizeUserProfile(lilaDefaultUserProfile());
  }

