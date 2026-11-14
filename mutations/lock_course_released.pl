# Never release the course's lock: every later writer of that course waits it out and is refused.
s/\$lock->release\(\);//;
