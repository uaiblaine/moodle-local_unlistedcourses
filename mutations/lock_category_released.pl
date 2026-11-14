# Never release the category's lock: every later writer of that category waits it out and is refused.
s/\$lock->release\(\);//;
